<?php

namespace App\Domain\Billing;

use App\Domain\Import\Tabular;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;

/**
 * Management reports. The contribution P&L is operational: it adds up the VERIFIED charges of the SPMs (selling and buying, Plan and
 * Unplan) and shows what is not verified yet beside it; it is not the general-ledger profit and loss, which lives in the accounting
 * system. Nothing here writes.
 */
final class ReportService
{
    public const DIMENSIONS = ['spm' => 'SPM', 'customer' => 'Pelanggan', 'project' => 'Proyek', 'vehicle' => 'Kendaraan', 'vendor' => 'Vendor', 'lane' => 'Rute'];

    public function __construct(private readonly TenantStore $store) {}

    /** @return list<array{key: string, name: string, description: string, permission: string, dimensions?: array<string, string>}> */
    public function catalog(): array
    {
        return [
            ['key' => 'pnl', 'name' => 'P&L kontribusi', 'description' => 'Margin operasional dari biaya jual dan beli terverifikasi per SPM, pelanggan, proyek, kendaraan, vendor, atau rute.', 'permission' => 'report.pnl.view', 'dimensions' => self::DIMENSIONS],
            ['key' => 'ready_to_bill', 'name' => 'Siap tagih', 'description' => 'Biaya jual terverifikasi yang belum masuk invoice, per SPM.', 'permission' => 'billing.issue'],
            ['key' => 'ar_aging', 'name' => 'Umur piutang', 'description' => 'Invoice pelanggan yang belum lunas menurut umur jatuh tempo.', 'permission' => 'finance.access'],
            ['key' => 'spm_status', 'name' => 'Status SPM', 'description' => 'Jumlah SPM per status, dan biaya yang menunggu verifikasi.', 'permission' => 'operations.view'],
        ];
    }

    /**
     * @return array{group_by: string, from: ?string, to: ?string, rows: list<array<string, mixed>>, totals: array<string, int|float|null>}
     */
    public function pnl(string $groupBy, ?string $from, ?string $to): array
    {
        abort_unless(isset(self::DIMENSIONS[$groupBy]), 422, 'Dimensi laporan tidak dikenal.');
        $query = $this->store->query('spm_charges')->whereIn('status', ['VERIFIED', 'PROPOSED']);
        if ($from) {
            $query->where(fn ($q) => $q->where('status', 'PROPOSED')->orWhere('verified_at', '>=', $from.' 00:00:00'));
        }
        if ($to) {
            $query->where(fn ($q) => $q->where('status', 'PROPOSED')->orWhere('verified_at', '<=', $to.' 23:59:59'));
        }
        $charges = $query->get();
        $spms = $this->store->query('transport_orders')->whereIn('id', $charges->pluck('transport_order_id')->unique())->get()->keyBy('id');
        $customers = $this->store->query('customers')->pluck('name', 'id');
        $projects = $this->store->query('customer_projects')->pluck('name', 'id');
        $vendors = $this->store->query('vendors')->pluck('name', 'id');

        // Per SPM first: verified amounts by side and kind, and what still waits.
        $per = [];
        foreach ($charges as $c) {
            $row = &$per[$c->transport_order_id];
            $row ??= ['sell' => 0, 'buy' => 0, 'sell_plan' => 0, 'sell_unplan' => 0, 'buy_plan' => 0, 'buy_unplan' => 0, 'unverified_sell' => 0, 'unverified_buy' => 0];
            $side = strtolower($c->side);
            if ($c->status === 'VERIFIED') {
                $row[$side] += (int) $c->amount_minor;
                $row[$side.'_'.strtolower($c->kind)] += (int) $c->amount_minor;
            } else {
                $row['unverified_'.$side] += (int) $c->amount_minor;
            }
            unset($row);
        }

        $trips = $groupBy === 'vehicle' ? $this->store->query('spm_trips')->where('status', '<>', 'CANCELLED')->whereIn('transport_order_id', array_keys($per))->get()->groupBy('transport_order_id') : collect();
        $plates = $groupBy === 'vehicle' ? $this->store->query('vehicles')->pluck('plate', 'id') : collect();

        $rows = [];
        $add = function (string $key, string $label, int $spmShare, array $m, float $fraction) use (&$rows) {
            $r = &$rows[$key];
            $r ??= ['key' => $key, 'label' => $label, 'spm_count' => 0, 'sell_minor' => 0, 'buy_minor' => 0, 'sell_plan_minor' => 0, 'sell_unplan_minor' => 0, 'buy_plan_minor' => 0, 'buy_unplan_minor' => 0, 'unverified_sell_minor' => 0, 'unverified_buy_minor' => 0];
            $r['spm_count'] += $spmShare;
            foreach (['sell', 'buy', 'sell_plan', 'sell_unplan', 'buy_plan', 'buy_unplan'] as $f) {
                $r[$f.'_minor'] += (int) round($m[$f] * $fraction);
            }
            $r['unverified_sell_minor'] += (int) round($m['unverified_sell'] * $fraction);
            $r['unverified_buy_minor'] += (int) round($m['unverified_buy'] * $fraction);
            unset($r);
        };
        foreach ($per as $spmId => $m) {
            $spm = $spms[$spmId] ?? null;
            if ($spm === null) {
                continue;
            }
            match ($groupBy) {
                'spm' => $add($spm->id, $spm->number, 1, $m, 1.0),
                'customer' => $add($spm->customer_id ?? 'none', $spm->customer_id ? ($customers[$spm->customer_id] ?? '—') : 'Tanpa pelanggan master', 1, $m, 1.0),
                'project' => $add($spm->project_id ?? 'none', $spm->project_id ? ($projects[$spm->project_id] ?? '—') : 'Tanpa proyek', 1, $m, 1.0),
                'vendor' => $add($spm->vendor_id ?? 'own', $spm->vendor_id ? ($vendors[$spm->vendor_id] ?? '—') : 'Armada sendiri', 1, $m, 1.0),
                'lane' => $add($spm->origin_text.'|'.$spm->destination_text, $spm->origin_text.' → '.$spm->destination_text, 1, $m, 1.0),
                'vehicle' => $this->byVehicle($spm, $m, $trips[$spmId] ?? collect(), $plates, $add),
            };
        }
        $rows = array_values($rows);
        foreach ($rows as &$r) {
            $r['margin_minor'] = $r['sell_minor'] - $r['buy_minor'];
            $r['margin_bps'] = $r['sell_minor'] > 0 ? (int) round($r['margin_minor'] * 10000 / $r['sell_minor']) : null;
        }
        unset($r);
        usort($rows, fn ($a, $b) => $b['margin_minor'] <=> $a['margin_minor']);
        $sell = array_sum(array_column($rows, 'sell_minor'));
        $buy = array_sum(array_column($rows, 'buy_minor'));

        return ['group_by' => $groupBy, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => [
            'sell_minor' => $sell, 'buy_minor' => $buy, 'margin_minor' => $sell - $buy, 'margin_bps' => $sell > 0 ? (int) round(($sell - $buy) * 10000 / $sell) : null,
            'unverified_sell_minor' => array_sum(array_column($rows, 'unverified_sell_minor')), 'unverified_buy_minor' => array_sum(array_column($rows, 'unverified_buy_minor')),
        ]];
    }

    /** The result of an SPM goes to the vehicles of its trips in proportion to the part of the price each trip carries. */
    private function byVehicle(object $spm, array $m, $trips, $plates, \Closure $add): void
    {
        if ($trips->isEmpty()) {
            $add('none', 'Tanpa kendaraan', 1, $m, 1.0);

            return;
        }
        $weight = $trips->sum(fn ($t) => max(1, (int) $t->sell_minor));
        foreach ($trips as $t) {
            $add($t->vehicle_id, $plates[$t->vehicle_id] ?? '—', 0, $m, max(1, (int) $t->sell_minor) / $weight);
        }
        // The SPM counts once, for its first vehicle.
        $first = $trips->first();
        $add($first->vehicle_id, $plates[$first->vehicle_id] ?? '—', 1, array_fill_keys(array_keys($m), 0), 0.0);
    }

    /** @return list<array<string, mixed>> */
    public function arAging(): array
    {
        $today = CarbonImmutable::today();
        $docs = $this->store->query('ledger_documents')->where('kind', 'CUSTOMER_INVOICE')->whereIn('status', ['OPEN', 'PARTIAL'])->get();
        $rows = [];
        foreach ($docs as $d) {
            $late = $today->diffInDays(CarbonImmutable::parse($d->due_date), false) * -1;
            $bucket = $late <= 0 ? 'current' : ($late <= 30 ? 'd1_30' : ($late <= 60 ? 'd31_60' : ($late <= 90 ? 'd61_90' : 'd90_plus')));
            $r = &$rows[$d->counterparty_name];
            $r ??= ['customer' => $d->counterparty_name, 'current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0, 'documents' => 0];
            $open = (int) $d->amount_minor - (int) $d->paid_minor;
            $r[$bucket] += $open;
            $r['total'] += $open;
            $r['documents']++;
            unset($r);
        }

        return array_values($rows);
    }

    /** @return array{statuses: array<string, int>, charges_waiting: array{sell: int, buy: int}} */
    public function spmStatus(): array
    {
        return [
            'statuses' => $this->store->query('transport_orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'charges_waiting' => [
                'sell' => $this->store->query('spm_charges')->where('side', 'SELL')->where('status', 'PROPOSED')->count(),
                'buy' => $this->store->query('spm_charges')->where('side', 'BUY')->where('status', 'PROPOSED')->count(),
            ],
        ];
    }

    /** A P&L as a spreadsheet file: @return array{0: string, 1: string} path and download name */
    public function exportPnl(array $report, string $format): array
    {
        $header = ['Kunci', 'SPM', 'Jual', 'Beli', 'Margin', 'Margin (%)', 'Jual Plan', 'Jual Unplan', 'Beli Plan', 'Beli Unplan', 'Jual belum diverifikasi', 'Beli belum diverifikasi'];
        $money = fn (int $minor) => number_format($minor / 100, 2, '.', '');
        $rows = array_map(fn ($r) => [
            $r['label'], $r['spm_count'], $money($r['sell_minor']), $money($r['buy_minor']), $money($r['margin_minor']), $r['margin_bps'] === null ? '' : number_format($r['margin_bps'] / 100, 2, '.', ''),
            $money($r['sell_plan_minor']), $money($r['sell_unplan_minor']), $money($r['buy_plan_minor']), $money($r['buy_unplan_minor']), $money($r['unverified_sell_minor']), $money($r['unverified_buy_minor']),
        ], $report['rows']);

        return [Tabular::write($format, [['P&L '.self::DIMENSIONS[$report['group_by']], $header, $rows]]), 'pnl-'.$report['group_by'].'-'.now()->format('Ymd').'.'.$format];
    }
}
