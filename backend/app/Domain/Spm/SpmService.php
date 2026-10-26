<?php

namespace App\Domain\Spm;

use App\Domain\Customer\Webhooks;
use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * The SPM (Surat Perintah Muat / transport order): the unit of work and of billing between an order and its trips. It is
 * drafted from a confirmed order (an order can be split into several SPMs), carries shipment lines, and moves through
 * DRAFT → PLANNED → LOADING → IN_TRANSIT → DISCHARGED → DOC_VERIFIED → CLOSED (REOPENED and CANCELLED need a reason). When it is
 * planned, the tariffs are resolved once for both sides and written as charges; later tariff changes do not touch them, a
 * correction is a new charge line with a reason.
 */
final class SpmService
{
    /** from => allowed targets */
    public const FLOW = [
        'DRAFT' => ['PLANNED', 'CANCELLED'],
        'PLANNED' => ['LOADING', 'CANCELLED'],
        'LOADING' => ['IN_TRANSIT'],
        'IN_TRANSIT' => ['DISCHARGED'],
        'DISCHARGED' => ['DOC_VERIFIED', 'REOPENED'],
        'DOC_VERIFIED' => ['CLOSED', 'REOPENED'],
        'CLOSED' => ['REOPENED'],
        'REOPENED' => ['DISCHARGED', 'DOC_VERIFIED'],
        'CANCELLED' => [],
    ];

    /** Fields of a line that can still be recorded after loading starts. */
    private const ACTUALS = ['damage_qty', 'loss_qty', 'over_qty', 'seal_number', 'notes'];

    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function active(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->store->tenantId)->where('active', true);
    }

    // ---------------------------------------------------------------- reading

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function list(?string $status, ?string $search, ?string $orderId, int $page, int $perPage = 50): array
    {
        $query = $this->store->query('transport_orders');
        if ($status !== null && isset(self::FLOW[$status])) {
            $query->where('status', $status);
        }
        if ($orderId !== null && Str::isUuid($orderId)) {
            $query->where('order_id', $orderId);
        }
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(number) like ?', [$like])->orWhereRaw('lower(coalesce(reference, \'\')) like ?', [$like])->orWhereRaw('lower(origin_text) like ?', [$like])->orWhereRaw('lower(destination_text) like ?', [$like]));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();
        $orders = $this->store->query('orders')->whereIn('id', $rows->pluck('order_id'))->pluck('number', 'id');
        $customers = $this->store->query('customers')->whereIn('id', $rows->pluck('customer_id')->filter())->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->order_number = $orders[$row->order_id] ?? null;
            $row->customer_label = $row->customer_id ? ($customers[$row->customer_id] ?? null) : null;
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public function show(string $id, bool $canSeeBuy): array
    {
        $spm = $this->store->find('transport_orders', $id);
        $order = $this->store->find('orders', $spm->order_id);
        $labels = [];
        foreach (['customer_id' => 'customers', 'project_id' => 'customer_projects', 'service_id' => 'services', 'origin_location_id' => 'locations', 'destination_location_id' => 'locations', 'carrier_type_id' => 'carrier_types', 'vendor_id' => 'vendors'] as $column => $table) {
            $spm->{Str::beforeLast($column, '_id').'_label'} = $spm->{$column} ? optional($this->store->query($table)->where('id', $spm->{$column})->first(['code', 'name']), fn ($r) => $r->code.' · '.$r->name) : null;
        }
        $lines = $this->store->query('shipment_lines')->where('transport_order_id', $id)->orderBy('line_no')->get();
        $charges = $this->store->query('spm_charges')->where('transport_order_id', $id)->orderBy('side')->orderBy('created_at')->get();
        if (! $canSeeBuy) {
            $charges = $charges->where('side', 'SELL')->values();
        }
        $codes = $this->store->query('charge_codes')->whereIn('id', $charges->pluck('charge_code_id'))->get(['id', 'code', 'name'])->keyBy('id');
        foreach ($charges as $c) {
            $c->charge_code_label = isset($codes[$c->charge_code_id]) ? $codes[$c->charge_code_id]->code.' · '.$codes[$c->charge_code_id]->name : null;
        }
        $awbs = $this->store->query('awbs')->where('transport_order_id', $id)->orderBy('sequence')->get();
        $trips = $this->store->query('spm_trips')->whereIn('id', $awbs->pluck('trip_id')->filter())->pluck('number', 'id');
        $byAwb = $lines->groupBy('awb_id');
        foreach ($awbs as $awb) {
            $awb->trip_number = $awb->trip_id ? ($trips[$awb->trip_id] ?? null) : null;
            $awb->line_ids = ($byAwb[$awb->id] ?? collect())->pluck('id')->values()->all();
            $awb->weight_kg = (float) ($byAwb[$awb->id] ?? collect())->sum('weight_kg');
        }
        $history = $this->store->query('spm_status_history')->where('transport_order_id', $id)->orderBy('created_at')->get();
        $users = DB::table('users')->whereIn('id', $history->pluck('actor_id'))->pluck('name', 'id');
        foreach ($history as $h) {
            $h->actor_name = $users[$h->actor_id] ?? null;
        }
        $total = fn (string $side) => (int) $charges->where('side', $side)->sum('amount_minor');

        return [
            'spm' => $spm, 'order' => ['id' => $order->id, 'number' => $order->number, 'customer_name' => $order->customer_name, 'reference' => $order->reference, 'status' => $order->status],
            'lines' => $lines->all(), 'awbs' => $awbs->all(), 'charges' => $charges->all(), 'history' => $history->all(),
            'totals' => $canSeeBuy ? ['sell_minor' => $total('SELL'), 'buy_minor' => $total('BUY'), 'margin_minor' => $total('SELL') - $total('BUY')] : ['sell_minor' => $total('SELL')],
            'siblings' => $this->store->query('transport_orders')->where('order_id', $spm->order_id)->orderBy('sequence')->get(['id', 'number', 'sequence', 'status'])->all(),
        ];
    }

    // ---------------------------------------------------------------- creating

    private function headerRules(): array
    {
        return [
            'carrier_type_id' => ['nullable', 'uuid', $this->active('carrier_types')], 'vendor_id' => ['nullable', 'uuid', $this->active('vendors')],
            'service_id' => ['nullable', 'uuid', $this->active('services')],
            'origin_location_id' => ['nullable', 'uuid', $this->active('locations')], 'destination_location_id' => ['nullable', 'uuid', $this->active('locations')],
            'planned_pickup_at' => 'nullable|date', 'planned_delivery_at' => 'nullable|date|after_or_equal:planned_pickup_at',
            'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000',
        ];
    }

    private function lineRules(string $prefix = ''): array
    {
        return [
            $prefix.'po_number' => 'nullable|string|max:60', $prefix.'so_number' => 'nullable|string|max:60',
            $prefix.'goods_id' => ['nullable', 'uuid', $this->active('goods')], $prefix.'material' => 'required|string|max:200',
            $prefix.'quantity' => 'required|numeric|gt:0|max:99999999', $prefix.'unit_id' => ['nullable', 'uuid', $this->active('units')],
            $prefix.'pallets' => 'nullable|integer|min:0|max:100000', $prefix.'volume_cbm' => 'nullable|numeric|min:0|max:99999999', $prefix.'weight_kg' => 'nullable|numeric|min:0|max:999999999',
            $prefix.'seal_number' => 'nullable|string|max:60', $prefix.'slot_time' => 'nullable|date', $prefix.'notes' => 'nullable|string|max:300',
            $prefix.'damage_qty' => 'nullable|numeric|min:0|max:99999999', $prefix.'loss_qty' => 'nullable|numeric|min:0|max:99999999', $prefix.'over_qty' => 'nullable|numeric|min:0|max:99999999',
        ];
    }

    private const LINE_COLUMNS = ['po_number', 'so_number', 'goods_id', 'material', 'quantity', 'unit_id', 'pallets', 'volume_cbm', 'weight_kg', 'seal_number', 'slot_time', 'notes', 'damage_qty', 'loss_qty', 'over_qty'];

    private function lineRow(array $line): array
    {
        $row = [];
        foreach (self::LINE_COLUMNS as $column) {
            $row[$column] = $line[$column] ?? (in_array($column, ['damage_qty', 'loss_qty', 'over_qty'], true) ? 0 : null);
        }

        return $row;
    }

    /** Lines taken from the items of the order (weight in kg, volume in CBM). */
    private function linesFromOrder(string $orderId): array
    {
        return $this->store->query('order_items')->where('order_id', $orderId)->get()->map(fn ($i) => [
            'material' => $i->description, 'quantity' => $i->quantity,
            'weight_kg' => round($i->quantity * $i->unit_weight_g / 1000, 3), 'volume_cbm' => round($i->quantity * $i->length_mm * $i->width_mm * $i->height_mm / 1e9, 3),
        ])->all();
    }

    public function create(string $orderId, array $input): array
    {
        $header = Validator::make($input, $this->headerRules() + ['lines' => 'nullable|array|max:500'] + $this->lineRules('lines.*.'), [], ['lines.*.material' => 'Material', 'lines.*.quantity' => 'Jumlah'])->validate();

        return DB::transaction(function () use ($orderId, $header) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $order = $this->store->find('orders', $orderId, true);
            abort_unless(in_array($order->status, ['CONFIRMED', 'DISPATCHED', 'IN_TRANSIT'], true), 409, 'SPM hanya dapat dibuat dari order yang sudah dikonfirmasi dan belum selesai.');
            $lines = $header['lines'] ?? $this->linesFromOrder($orderId);
            unset($header['lines']);
            if ($lines === []) {
                $this->fail('lines', 'SPM membutuhkan minimal satu baris muatan.');
            }
            $origin = $header['origin_location_id'] ?? $order->origin_location_id;
            $destination = $header['destination_location_id'] ?? $order->destination_location_id;
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('SPM-%s-%05d', $period, DocumentNumbers::next($this->store->tenantId, 'SPM', $period));
            $sequence = (int) $this->store->query('transport_orders')->where('order_id', $orderId)->max('sequence') + 1;
            DB::table('transport_orders')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'order_id' => $orderId, 'sequence' => $sequence, 'status' => 'DRAFT',
                'customer_id' => $order->customer_id, 'project_id' => $order->project_id, 'service_id' => $header['service_id'] ?? $order->service_id,
                'origin_location_id' => $origin, 'destination_location_id' => $destination,
                'origin_text' => $origin ? mb_substr($this->store->find('locations', $origin)->name, 0, 300) : $order->origin,
                'destination_text' => $destination ? mb_substr($this->store->find('locations', $destination)->name, 0, 300) : $order->destination,
                'carrier_type_id' => $header['carrier_type_id'] ?? null, 'vendor_id' => $header['vendor_id'] ?? null, 'reference' => $header['reference'] ?? $order->reference,
                'planned_pickup_at' => $header['planned_pickup_at'] ?? null, 'planned_delivery_at' => $header['planned_delivery_at'] ?? null, 'notes' => $header['notes'] ?? null,
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_values($lines) as $i => $line) {
                DB::table('shipment_lines')->insert($this->lineRow($line) + ['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $id, 'line_no' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->history($id, null, 'DRAFT', null);
            $this->store->event('spm.created', $id, ['number' => $number, 'order_id' => $orderId, 'sequence' => $sequence, 'lines' => count($lines)]);

            return $this->show($id, true);
        }, 3);
    }

    private function history(string $id, ?string $from, string $to, ?string $reason): void
    {
        DB::table('spm_status_history')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $this->store->actorId, 'created_at' => now()]);
    }

    // ---------------------------------------------------------------- editing

    public function update(string $id, array $input): array
    {
        $d = Validator::make($input, $this->headerRules())->validate();

        return DB::transaction(function () use ($id, $d) {
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless($spm->status === 'DRAFT', 409, 'SPM hanya dapat diubah selagi draf. Tujuan dapat diganti dengan alasan.');
            $row = [];
            foreach (['carrier_type_id', 'vendor_id', 'service_id', 'planned_pickup_at', 'planned_delivery_at', 'reference', 'notes'] as $column) {
                $row[$column] = $d[$column] ?? null;
            }
            foreach (['origin' => 'origin_location_id', 'destination' => 'destination_location_id'] as $text => $column) {
                $row[$column] = $d[$column] ?? $spm->{$column};
                if ($row[$column] !== $spm->{$column} && $row[$column] !== null) {
                    $row[$text.'_text'] = mb_substr($this->store->find('locations', $row[$column])->name, 0, 300);
                }
            }
            DB::table('transport_orders')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($row + ['updated_at' => now()]);
            $this->store->event('spm.updated', $id, ['number' => $spm->number]);

            return $this->show($id, true);
        }, 3);
    }

    private function editableLine(object $spm, array $input): void
    {
        if ($spm->status === 'DRAFT') {
            return;
        }
        abort_unless(in_array($spm->status, ['LOADING', 'IN_TRANSIT', 'DISCHARGED', 'REOPENED'], true), 409, 'Baris muatan tidak dapat diubah pada status ini.');
        $changed = array_diff(array_keys(array_intersect_key($input, array_flip(self::LINE_COLUMNS))), self::ACTUALS);
        if ($changed !== []) {
            $this->fail('material', 'Setelah SPM direncanakan hanya segel, rusak, hilang, lebih, dan catatan yang dapat diubah.');
        }
    }

    public function addLine(string $id, array $input): object
    {
        $d = Validator::make($input, $this->lineRules())->validate();

        return DB::transaction(function () use ($id, $d) {
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless($spm->status === 'DRAFT', 409, 'Baris hanya dapat ditambah selagi SPM masih draf.');
            $no = (int) $this->store->query('shipment_lines')->where('transport_order_id', $id)->max('line_no') + 1;
            $lineId = (string) Str::uuid();
            DB::table('shipment_lines')->insert($this->lineRow($d) + ['id' => $lineId, 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $id, 'line_no' => $no, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('spm.line.added', $id, ['number' => $spm->number, 'line_no' => $no]);

            return $this->store->find('shipment_lines', $lineId);
        }, 3);
    }

    public function updateLine(string $id, string $lineId, array $input): object
    {
        $d = Validator::make($input, array_map(fn ($r) => is_string($r) ? str_replace('required|', 'sometimes|required|', $r) : $r, $this->lineRules()))->validate();

        return DB::transaction(function () use ($id, $lineId, $d) {
            $spm = $this->store->find('transport_orders', $id, true);
            $this->editableLine($spm, $d);
            $line = $this->store->query('shipment_lines')->where('transport_order_id', $id)->where('id', $lineId)->lockForUpdate()->firstOrFail();
            $row = $spm->status === 'DRAFT' ? $this->lineRow($d + (array) $line) : array_intersect_key($d, array_flip(self::ACTUALS));
            $row = array_filter($row, fn ($v, $k) => $spm->status === 'DRAFT' || array_key_exists($k, $d), ARRAY_FILTER_USE_BOTH);
            DB::table('shipment_lines')->where('tenant_id', $this->store->tenantId)->where('id', $lineId)->update($row + ['updated_at' => now()]);
            $this->store->event('spm.line.updated', $id, ['number' => $spm->number, 'line_no' => $line->line_no, 'fields' => array_keys($d)]);

            return $this->store->find('shipment_lines', $lineId);
        }, 3);
    }

    public function deleteLine(string $id, string $lineId): void
    {
        DB::transaction(function () use ($id, $lineId) {
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless($spm->status === 'DRAFT', 409, 'Baris hanya dapat dihapus selagi SPM masih draf.');
            $line = $this->store->query('shipment_lines')->where('transport_order_id', $id)->where('id', $lineId)->firstOrFail();
            abort_if($this->store->query('shipment_lines')->where('transport_order_id', $id)->count() <= 1, 422, 'SPM membutuhkan minimal satu baris muatan.');
            DB::table('shipment_lines')->where('tenant_id', $this->store->tenantId)->where('id', $lineId)->delete();
            $this->store->event('spm.line.removed', $id, ['number' => $spm->number, 'line_no' => $line->line_no]);
        }, 3);
    }

    // ---------------------------------------------------------------- status

    /** @return array{spm: array<string, mixed>, warnings: list<string>} */
    public function transition(string $id, string $to, ?string $reason, bool $viaQueue = false): array
    {
        abort_if($to === 'REOPENED' && ! $viaQueue, 422, 'SPM dibuka kembali lewat permintaan buka kembali yang disetujui orang lain.');

        return DB::transaction(function () use ($id, $to, $reason) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless(in_array($to, self::FLOW[$spm->status] ?? [], true), 409, "Status {$spm->status} tidak dapat langsung menjadi {$to}.");
            if (in_array($to, ['CANCELLED', 'REOPENED'], true) && mb_strlen(trim((string) $reason)) < 5) {
                $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
            }
            if ($to === 'CANCELLED' && $this->store->query('spm_trips')->where('transport_order_id', $id)->where('active', true)->exists()) {
                abort(409, 'SPM masih memiliki perjalanan aktif. Batalkan perjalanannya lebih dulu.');
            }
            $warnings = [];
            if ($to === 'PLANNED') {
                $warnings = $this->plan($spm);
            }
            DB::table('transport_orders')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => $to, 'status_reason' => in_array($to, ['CANCELLED', 'REOPENED'], true) ? $reason : $spm->status_reason, 'updated_at' => now()]);
            $this->history($id, $spm->status, $to, $reason);
            $this->store->event('spm.status_changed', $id, ['number' => $spm->number, 'from' => $spm->status, 'to' => $to, 'reason' => $reason, 'warnings' => $warnings]);
            // What the customer is told: no internal reason, and nothing before the SPM leaves draft.
            if ($spm->status !== 'DRAFT' || $to !== 'CANCELLED') {
                Webhooks::publish($this->store->tenantId, $spm->customer_id, 'spm.status_changed', $id, ['spm' => ['id' => $id, 'number' => $spm->number, 'reference' => $spm->reference, 'from' => $spm->status, 'to' => $to]]);
            }

            return ['spm' => $this->show($id, true), 'warnings' => $warnings];
        }, 3);
    }

    /** Checks the SPM is ready to be planned and prices it. @return list<string> warnings */
    private function plan(object $spm): array
    {
        if (! $spm->origin_location_id || ! $spm->destination_location_id) {
            $this->fail('origin_location_id', 'Pilih lokasi asal dan tujuan dari master data sebelum SPM direncanakan.');
        }

        (new AwbService($this->store))->ensureComplete($spm);

        return $this->price($spm);
    }

    /**
     * Resolves the tariffs of both sides and replaces the tariff-priced charges of the SPM. Corrections and other manual lines stay.
     *
     * @return list<string> warnings
     */
    private function price(object $spm): array
    {
        $lines = $this->store->query('shipment_lines')->where('transport_order_id', $spm->id)->get();
        $result = (new TariffResolver($this->store))->resolve(array_filter([
            'date' => $spm->planned_pickup_at ? substr((string) $spm->planned_pickup_at, 0, 10) : now()->toDateString(),
            'customer_id' => $spm->customer_id, 'project_id' => $spm->project_id, 'vendor_id' => $spm->vendor_id,
            'origin_location_id' => $spm->origin_location_id, 'destination_location_id' => $spm->destination_location_id,
            'carrier_type_id' => $spm->carrier_type_id, 'service_id' => $spm->service_id,
            'quantities' => ['trips' => 1, 'drop' => 1, 'kg' => (float) $lines->sum('weight_kg'), 'cbm' => (float) $lines->sum('volume_cbm'), 'carton' => (float) $lines->sum('quantity')],
        ], fn ($v) => $v !== null));

        $this->store->query('spm_charges')->where('transport_order_id', $spm->id)->where('source', 'RATE')->where('status', 'PROPOSED')->delete();
        foreach ($result['lines'] as $line) {
            foreach (['SELL' => $line['sell'], 'BUY' => $line['buy'] ?? null] as $side => $priced) {
                if ($priced === null || $priced['amount_minor'] === null) {
                    continue;
                }
                DB::table('spm_charges')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $spm->id, 'side' => $side, 'charge_code_id' => $line['charge_code']['id'],
                    'kind' => 'PLAN', 'source' => 'RATE', 'basis' => $priced['basis'], 'quantity' => $priced['quantity'], 'unit_price_minor' => $priced['unit_price_minor'], 'amount_minor' => $priced['amount_minor'],
                    'rate_table_id' => $priced['rate_table_id'] ?? null, 'rate_label' => isset($priced['table_code']) ? $priced['table_code'].' v'.$priced['version'] : null,
                    'markup_code' => $priced['markup']['code'] ?? null, 'status' => 'PROPOSED', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('transport_orders')->where('tenant_id', $this->store->tenantId)->where('id', $spm->id)->update(['priced_at' => now()]);
        $this->store->event('spm.priced', $spm->id, ['number' => $spm->number] + $result['totals']);

        return $result['warnings'];
    }

    /** Changes the destination before the cargo is on the road; while PLANNED the tariffs are resolved again. */
    public function changeDestination(string $id, string $locationId, string $reason): array
    {
        $d = Validator::make(['destination_location_id' => $locationId, 'reason' => $reason], ['destination_location_id' => ['required', 'uuid', $this->active('locations')], 'reason' => 'required|string|min:5|max:500'], [], ['reason' => 'Alasan', 'destination_location_id' => 'Tujuan'])->validate();

        return DB::transaction(function () use ($id, $d) {
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless(in_array($spm->status, ['DRAFT', 'PLANNED', 'LOADING'], true), 409, 'Tujuan hanya dapat diganti sebelum muatan berangkat.');
            $name = mb_substr($this->store->find('locations', $d['destination_location_id'])->name, 0, 300);
            DB::table('transport_orders')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['destination_location_id' => $d['destination_location_id'], 'destination_text' => $name, 'updated_at' => now()]);
            $warnings = [];
            $repriced = false;
            if ($spm->status === 'PLANNED') {
                $warnings = $this->price($this->store->find('transport_orders', $id));
                $repriced = true;
            } elseif ($spm->status === 'LOADING') {
                $warnings[] = 'Tarif tidak dihitung ulang karena muatan sudah dalam proses. Tambahkan koreksi biaya bila perlu.';
            }
            $this->history($id, $spm->status, $spm->status, 'Tujuan diganti ke '.$name.': '.$d['reason']);
            $this->store->event('spm.destination_changed', $id, ['number' => $spm->number, 'from' => $spm->destination_text, 'to' => $name, 'reason' => $d['reason'], 'repriced' => $repriced]);

            return ['spm' => $this->show($id, true), 'warnings' => $warnings];
        }, 3);
    }

    // ---------------------------------------------------------------- charges

    /** A manual charge outside the tariff: a correction or an extra cost, always with a reason. */
    public function addCharge(string $id, array $input): object
    {
        $d = Validator::make($input, [
            'side' => 'required|in:SELL,BUY', 'charge_code_id' => ['required', 'uuid', $this->active('charge_codes')],
            'quantity' => 'nullable|numeric|gt:0|max:99999999', 'amount_minor' => 'required|integer|between:-100000000000000,100000000000000|not_in:0', 'reason' => 'required|string|min:5|max:500',
        ], [], ['reason' => 'Alasan', 'amount_minor' => 'Jumlah', 'charge_code_id' => 'Kode biaya'])->validate();

        return DB::transaction(function () use ($id, $d) {
            $spm = $this->store->find('transport_orders', $id, true);
            abort_unless(in_array($spm->status, ['PLANNED', 'LOADING', 'IN_TRANSIT', 'DISCHARGED', 'REOPENED'], true), 409, 'Biaya tambahan hanya dapat dicatat setelah SPM direncanakan dan sebelum ditutup.');
            $charge = $this->store->query('charge_codes')->where('id', $d['charge_code_id'])->first();
            if ($charge->side !== 'BOTH' && $charge->side !== $d['side']) {
                $this->fail('charge_code_id', 'Kode biaya ini tidak berlaku untuk sisi yang dipilih.');
            }
            $chargeId = (string) Str::uuid();
            DB::table('spm_charges')->insert([
                'id' => $chargeId, 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $id, 'side' => $d['side'], 'charge_code_id' => $d['charge_code_id'], 'kind' => 'UNPLAN', 'source' => 'MANUAL',
                'quantity' => $d['quantity'] ?? null, 'amount_minor' => $d['amount_minor'], 'status' => 'PROPOSED', 'reason' => $d['reason'], 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('spm.charge.added', $id, ['number' => $spm->number, 'side' => $d['side'], 'charge_code' => $charge->code, 'amount_minor' => $d['amount_minor'], 'reason' => $d['reason']]);

            return $this->store->find('spm_charges', $chargeId);
        }, 3);
    }
}
