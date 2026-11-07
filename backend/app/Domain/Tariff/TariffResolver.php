<?php

namespace App\Domain\Tariff;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\Validator;

/**
 * Finds the price of a move from the approved tariffs. For each charge code the most specific approved line wins: a project
 * tariff over a customer tariff over a general one, then a narrower lane (location over city over zone over anywhere), then a
 * given carrier type, then a given service. The BUY side comes from the vendor's (or the own fleet's) tariff. A charge with no
 * SELL line but a BUY line is sold at BUY plus the most specific applicable markup rule, so a fixed selling tariff always
 * overrides a computed one. The result is a plain array that can be stored as the snapshot of a transport order.
 */
final class TariffResolver
{
    private const QUANTITY_KEYS = ['TRIP' => 'trips', 'KG' => 'kg', 'CBM' => 'cbm', 'CARTON' => 'carton', 'KM' => 'km', 'DAY' => 'day', 'DROP' => 'drop', 'UNIT' => 'unit'];

    public function __construct(private readonly TenantStore $store) {}

    /** @return array<string, mixed> */
    public function resolve(array $input): array
    {
        $in = Validator::make($input, [
            'date' => 'nullable|date_format:Y-m-d',
            'customer_id' => 'nullable|uuid', 'project_id' => 'nullable|uuid', 'vendor_id' => 'nullable|uuid',
            'origin_location_id' => 'required|uuid', 'destination_location_id' => 'required|uuid',
            'carrier_type_id' => 'nullable|uuid', 'service_id' => 'nullable|uuid', 'unit_id' => 'nullable|uuid',
            'charge_code_ids' => 'nullable|array|max:50', 'charge_code_ids.*' => 'uuid',
            'quantities' => 'nullable|array',
            'quantities.trips' => 'nullable|numeric|min:0', 'quantities.kg' => 'nullable|numeric|min:0', 'quantities.cbm' => 'nullable|numeric|min:0', 'quantities.carton' => 'nullable|numeric|min:0',
            'quantities.km' => 'nullable|numeric|min:0', 'quantities.day' => 'nullable|numeric|min:0', 'quantities.drop' => 'nullable|numeric|min:0', 'quantities.unit' => 'nullable|numeric|min:0',
        ])->validate();
        $date = $in['date'] ?? now()->toDateString();
        $quantities = ($in['quantities'] ?? []) + ['trips' => 1];
        $warnings = [];

        $origin = $this->place($in['origin_location_id'], 'origin_location_id');
        $destination = $this->place($in['destination_location_id'], 'destination_location_id');
        $project = null;
        if (! empty($in['project_id'])) {
            $project = $this->store->query('customer_projects')->where('id', $in['project_id'])->first() ?? abort(422, 'Proyek tidak ditemukan.');
            $in['customer_id'] ??= $project->customer_id;
        }
        $ctx = $in + ['origin' => $origin, 'destination' => $destination];

        $sell = $this->best('SELL', $ctx, $date, $warnings);
        $buy = $this->best('BUY', $ctx, $date, $warnings);
        $markup = null;
        $lines = [];
        $charges = $this->store->query('charge_codes')->whereIn('id', array_unique(array_merge(array_keys($sell), array_keys($buy))))->get()->keyBy('id');
        foreach ($charges->sortBy(fn ($c) => [$c->nature === 'FREIGHT' ? 0 : 1, $c->code]) as $charge) {
            if (! empty($in['charge_code_ids']) && ! in_array($charge->id, $in['charge_code_ids'], true)) {
                continue;
            }
            $b = isset($buy[$charge->id]) ? $this->price($buy[$charge->id], $quantities, $warnings, $charge->code) : null;
            $s = isset($sell[$charge->id]) ? $this->price($sell[$charge->id], $quantities, $warnings, $charge->code) : null;
            if ($s === null && $b !== null && isset($buy[$charge->id])) {
                $s = $this->mapped($buy[$charge->id]['line'], $b, $ctx);
            }
            if ($s === null && $b !== null && $b['amount_minor'] !== null) {
                $markup ??= $this->markup($ctx, $date, $warnings);
                if ($markup !== null && $charge->nature === 'FREIGHT') {
                    $s = ['source' => 'MARKUP', 'rate_table_id' => $b['rate_table_id'], 'table_code' => $b['table_code'], 'version' => $b['version'], 'rate_line_id' => $b['rate_line_id'], 'basis' => $b['basis'], 'quantity' => $b['quantity'], 'unit_price_minor' => null, 'min_charge_minor' => null, 'amount_minor' => $this->marked($b['amount_minor'], $markup, ! isset($markup['_applied'])), 'markup' => ['rule_id' => $markup['id'], 'code' => $markup['code'], 'mode' => $markup['mode'], 'percent_bps' => $markup['percent_bps'], 'amount_minor' => $markup['amount_minor']]];
                    $markup['_applied'] = true;
                } elseif ($markup === null) {
                    $warnings[] = "{$charge->code}: ada tarif beli tetapi tidak ada tarif jual maupun aturan markup yang berlaku.";
                }
            }
            $lines[] = [
                'charge_code' => ['id' => $charge->id, 'code' => $charge->code, 'name' => $charge->name, 'nature' => $charge->nature],
                'buy' => $b, 'sell' => $s,
                'margin_minor' => $b !== null && $s !== null && $b['amount_minor'] !== null && $s['amount_minor'] !== null ? $s['amount_minor'] - $b['amount_minor'] : null,
            ];
        }
        if ($lines === []) {
            $warnings[] = 'Tidak ada tarif yang berlaku untuk rute, armada, dan tanggal ini.';
        }
        $totalBuy = array_sum(array_map(fn ($l) => $l['buy']['amount_minor'] ?? 0, $lines));
        $totalSell = array_sum(array_map(fn ($l) => $l['sell']['amount_minor'] ?? 0, $lines));

        return [
            'date' => $date,
            'lines' => $lines,
            'totals' => ['buy_minor' => $totalBuy, 'sell_minor' => $totalSell, 'margin_minor' => $totalSell - $totalBuy, 'margin_bps' => $totalSell > 0 ? (int) round(($totalSell - $totalBuy) * 10000 / $totalSell) : null],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** Strips what a user without rate.buy.view must not see. */
    public static function withoutBuy(array $result): array
    {
        foreach ($result['lines'] as &$line) {
            unset($line['buy'], $line['margin_minor']);
            if (isset($line['sell']['markup']) || isset($line['sell']['mapping'])) {
                $line['sell'] = array_diff_key($line['sell'], ['markup' => 1, 'mapping' => 1, 'min_charge_minor' => 1, 'rate_table_id' => 1, 'table_code' => 1, 'version' => 1, 'rate_line_id' => 1, 'source' => 1]) + ['source' => 'DERIVED'];
            }
        }
        unset($line);
        $result['totals'] = ['sell_minor' => $result['totals']['sell_minor']];
        $result['warnings'] = array_values(array_filter($result['warnings'], fn ($w) => ! str_contains($w, 'tarif beli')));

        return $result;
    }

    /** The location with the city and zone that locate it. */
    private function place(string $locationId, string $field): array
    {
        $location = $this->store->query('locations')->where('id', $locationId)->first() ?? abort(422, 'Lokasi tidak ditemukan.');
        $area = $location->area_id ? $this->store->query('areas')->where('id', $location->area_id)->first() : null;

        return ['location' => $location->id, 'city' => $location->city_id ?? $area?->city_id, 'zone' => $area?->zone_id];
    }

    /**
     * The most specific line of each charge code for one side.
     *
     * @return array<string, array{line: object, table: object, score: int}>
     */
    private function best(string $side, array $ctx, string $date, array &$warnings): array
    {
        $tables = $this->store->query('rate_tables')->where('side', $side)->where('status', 'APPROVED')->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date));
        if ($side === 'SELL') {
            $tables->where(fn ($q) => $q->whereNull('customer_id')->when(! empty($ctx['customer_id']), fn ($q) => $q->orWhere('customer_id', $ctx['customer_id'])))
                ->where(fn ($q) => $q->whereNull('project_id')->when(! empty($ctx['project_id']), fn ($q) => $q->orWhere('project_id', $ctx['project_id'])));
        } else {
            empty($ctx['vendor_id']) ? $tables->whereNull('vendor_id') : $tables->where('vendor_id', $ctx['vendor_id']);
        }
        $tables = $tables->get()->keyBy('id');
        if ($tables->isEmpty()) {
            return [];
        }
        $o = $ctx['origin'];
        $d = $ctx['destination'];
        $lines = $this->store->query('rate_lines')->whereIn('rate_table_id', $tables->keys())
            ->where(fn ($q) => $q->whereNull('origin_location_id')->orWhere('origin_location_id', $o['location']))
            ->where(fn ($q) => $q->whereNull('origin_city_id')->when($o['city'], fn ($q) => $q->orWhere('origin_city_id', $o['city'])))
            ->where(fn ($q) => $q->whereNull('origin_zone_id')->when($o['zone'], fn ($q) => $q->orWhere('origin_zone_id', $o['zone'])))
            ->where(fn ($q) => $q->whereNull('destination_location_id')->orWhere('destination_location_id', $d['location']))
            ->where(fn ($q) => $q->whereNull('destination_city_id')->when($d['city'], fn ($q) => $q->orWhere('destination_city_id', $d['city'])))
            ->where(fn ($q) => $q->whereNull('destination_zone_id')->when($d['zone'], fn ($q) => $q->orWhere('destination_zone_id', $d['zone'])))
            ->where(fn ($q) => $q->whereNull('carrier_type_id')->when(! empty($ctx['carrier_type_id']), fn ($q) => $q->orWhere('carrier_type_id', $ctx['carrier_type_id'])))
            ->where(fn ($q) => $q->whereNull('service_id')->when(! empty($ctx['service_id']), fn ($q) => $q->orWhere('service_id', $ctx['service_id'])))
            ->where(fn ($q) => $q->whereNull('unit_id')->when(! empty($ctx['unit_id']), fn ($q) => $q->orWhere('unit_id', $ctx['unit_id'])))
            ->get();

        $best = [];
        foreach ($lines as $line) {
            $table = $tables[$line->rate_table_id];
            $lane = fn (string $p) => ($line->{$p.'_location_id'} ? 3 : ($line->{$p.'_city_id'} ? 2 : ($line->{$p.'_zone_id'} ? 1 : 0)));
            $score = ($table->project_id ? 2 : ($table->customer_id ? 1 : 0)) * 100000 + ($lane('origin') + $lane('destination')) * 1000 + ($line->carrier_type_id ? 10 : 0) + ($line->unit_id ? 5 : 0) + ($line->service_id ? 1 : 0);
            $key = $line->charge_code_id;
            $current = $best[$key] ?? null;
            if ($current === null || $score > $current['score']) {
                $best[$key] = ['line' => $line, 'table' => $table, 'score' => $score];
            } elseif ($score === $current['score'] && $line->id !== $current['line']->id) {
                $warnings[] = 'Beberapa tarif '.($side === 'SELL' ? 'jual' : 'beli').' sama spesifiknya untuk satu kode biaya ('.$current['table']->code.', '.$table->code.'); dipakai yang berlaku paling akhir.';
                if ($table->valid_from > $current['table']->valid_from || ($table->valid_from === $current['table']->valid_from && $line->id < $current['line']->id)) {
                    $best[$key] = ['line' => $line, 'table' => $table, 'score' => $score];
                }
            }
        }

        return $best;
    }

    /** @return array<string, mixed> */
    private function price(array $hit, array $quantities, array &$warnings, string $code): array
    {
        $line = $hit['line'];
        $quantity = $quantities[self::QUANTITY_KEYS[$line->basis]] ?? null;
        $amount = null;
        if ($quantity === null) {
            $warnings[] = "{$code}: kuantitas ".self::QUANTITY_KEYS[$line->basis].' belum diisi, nilainya belum dapat dihitung.';
        } else {
            $amount = max((int) $line->min_charge_minor, (int) round($line->unit_price_minor * (float) $quantity));
        }

        return [
            'source' => 'TABLE', 'rate_table_id' => $hit['table']->id, 'table_code' => $hit['table']->code, 'version' => $hit['table']->version, 'rate_line_id' => $line->id,
            'basis' => $line->basis, 'quantity' => $quantity === null ? null : (float) $quantity, 'unit_price_minor' => (int) $line->unit_price_minor, 'min_charge_minor' => (int) $line->min_charge_minor,
            'free_time_hours' => $line->free_time_hours, 'amount_minor' => $amount,
        ];
    }

    /**
     * The selling side of a mapping row: the initial rate plus the margin that applies to this customer (a project margin over a
     * customer margin over the row's own), or null when the row has no margin. The base tariff is rounded once, per unit.
     *
     * @return array<string, mixed>|null
     */
    private function mapped(object $line, array $buy, array $ctx): ?array
    {
        $mode = $line->margin_mode;
        $value = $line->margin_value;
        $scope = 'DEFAULT';
        $overrides = $this->store->query('rate_line_margins')->where('rate_line_id', $line->id)
            ->when(! empty($ctx['customer_id']), fn ($q) => $q->where('customer_id', $ctx['customer_id']), fn ($q) => $q->whereRaw('false'))->get();
        $own = ! empty($ctx['project_id']) ? $overrides->firstWhere('project_id', $ctx['project_id']) : null;
        $own ??= $overrides->first(fn ($m) => $m->project_id === null);
        if ($own !== null) {
            [$mode, $value, $scope] = [$own->margin_mode, $own->margin_value, $own->project_id ? 'PROJECT' : 'CUSTOMER'];
        }
        if ($mode === null) {
            return null;
        }
        $unitPrice = BaseTariff::price((int) $line->unit_price_minor, $mode, (int) $value);
        $minimum = BaseTariff::minimum((int) $line->min_charge_minor, $mode, (int) $value);

        return [
            'source' => 'MAPPING', 'rate_table_id' => $buy['rate_table_id'], 'table_code' => $buy['table_code'], 'version' => $buy['version'], 'rate_line_id' => $line->id,
            'basis' => $buy['basis'], 'quantity' => $buy['quantity'], 'unit_price_minor' => $unitPrice, 'min_charge_minor' => $minimum, 'free_time_hours' => $buy['free_time_hours'],
            'amount_minor' => $buy['quantity'] === null ? null : max($minimum, (int) round($unitPrice * $buy['quantity'])),
            'mapping' => ['initial_unit_price_minor' => (int) $line->unit_price_minor, 'margin_mode' => $mode, 'margin_value' => (int) $value, 'scope' => $scope],
        ];
    }

    /** The most specific active markup rule that applies, or null. */
    private function markup(array $ctx, string $date, array &$warnings): ?array
    {
        $rules = $this->store->query('markup_rules')->where('active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->where(fn ($q) => $q->whereNull('customer_id')->when(! empty($ctx['customer_id']), fn ($q) => $q->orWhere('customer_id', $ctx['customer_id'])))
            ->where(fn ($q) => $q->whereNull('project_id')->when(! empty($ctx['project_id']), fn ($q) => $q->orWhere('project_id', $ctx['project_id'])))
            ->where(fn ($q) => $q->whereNull('carrier_type_id')->when(! empty($ctx['carrier_type_id']), fn ($q) => $q->orWhere('carrier_type_id', $ctx['carrier_type_id'])))
            ->where(fn ($q) => $q->whereNull('service_id')->when(! empty($ctx['service_id']), fn ($q) => $q->orWhere('service_id', $ctx['service_id'])))
            ->where(fn ($q) => $q->whereNull('origin_city_id')->when($ctx['origin']['city'], fn ($q) => $q->orWhere('origin_city_id', $ctx['origin']['city'])))
            ->where(fn ($q) => $q->whereNull('destination_city_id')->when($ctx['destination']['city'], fn ($q) => $q->orWhere('destination_city_id', $ctx['destination']['city'])))
            ->get();
        $scored = $rules->map(function ($r) {
            $r->score = ($r->project_id ? 1000 : 0) + ($r->customer_id ? 100 : 0) + ($r->origin_city_id ? 10 : 0) + ($r->destination_city_id ? 10 : 0) + ($r->carrier_type_id ? 5 : 0) + ($r->service_id ? 5 : 0);

            return $r;
        })->sortByDesc('score')->values();
        if ($scored->count() > 1 && $scored[0]->score === $scored[1]->score) {
            $warnings[] = 'Beberapa aturan markup sama spesifiknya ('.$scored[0]->code.', '.$scored[1]->code.'); dipakai '.$scored[0]->code.'.';
        }

        return $scored->isEmpty() ? null : (array) $scored[0];
    }

    private function marked(int $buy, array $markup, bool $first): int
    {
        return $markup['mode'] === 'PERCENT' ? $buy + (int) round($buy * $markup['percent_bps'] / 10000) : $buy + ($first ? (int) $markup['amount_minor'] : 0);
    }
}
