<?php

namespace App\Domain\Tariff;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Tariffs and their lifecycle. A rate table is one version of one tariff: it is drafted (DRAFT, editable), approved by someone
 * other than its author (APPROVED, frozen), and ends by being retired or superseded by the next version. Every change is one
 * transaction with its audit and outbox event. A BUY table that is not shown to a user without rate.buy.view is filtered by the
 * caller, not here.
 */
final class TariffService
{
    public const BASES = ['TRIP' => 'Per trip', 'KG' => 'Per kg', 'CBM' => 'Per CBM', 'CARTON' => 'Per karton', 'KM' => 'Per km', 'DAY' => 'Per hari', 'DROP' => 'Per drop', 'UNIT' => 'Per satuan muatan'];

    /** Reference columns of a rate line and the master each points to. */
    public const LINE_REFS = [
        'origin_location_id' => 'locations', 'origin_city_id' => 'cities', 'origin_zone_id' => 'zones',
        'destination_location_id' => 'locations', 'destination_city_id' => 'cities', 'destination_zone_id' => 'zones',
        'carrier_type_id' => 'carrier_types', 'service_id' => 'services', 'charge_code_id' => 'charge_codes', 'unit_id' => 'units',
    ];

    private const TABLE_REFS = ['customer_id' => 'customers', 'project_id' => 'customer_projects', 'vendor_id' => 'vendors'];

    /** True for the copy a vendor works through: it may only change what the vendor itself proposed, and only until it is submitted. */
    private bool $asVendor = false;

    public function __construct(private readonly TenantStore $store) {}

    public function asVendor(): static
    {
        $copy = clone $this;
        $copy->asVendor = true;

        return $copy;
    }

    private function exists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->store->tenantId);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---------------------------------------------------------------- tables

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function list(?string $side, ?string $status, ?string $search, int $page, bool $canSeeBuy, int $perPage = 50): array
    {
        $query = $this->store->query('rate_tables');
        if (! $canSeeBuy) {
            $query->where('side', 'SELL');
        } elseif (in_array($side, ['SELL', 'BUY'], true)) {
            $query->where('side', $side);
        }
        if (in_array($status, ['DRAFT', 'APPROVED', 'RETIRED'], true)) {
            $query->where('status', $status);
        }
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('code')->orderByDesc('version')->forPage($page, $perPage)->get();
        $this->annotate($rows, self::TABLE_REFS);
        $counts = $this->store->query('rate_lines')->whereIn('rate_table_id', $rows->pluck('id'))->selectRaw('rate_table_id, count(*) as n')->groupBy('rate_table_id')->pluck('n', 'rate_table_id');
        foreach ($rows as $row) {
            $row->line_count = (int) ($counts[$row->id] ?? 0);
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    /** Adds `<column>_label` ("CODE · Name") for each reference column. */
    public function annotate($rows, array $refs): void
    {
        foreach ($refs as $column => $table) {
            $ids = $rows->pluck($column)->filter()->unique()->values()->all();
            $labels = $ids === [] ? collect() : $this->store->query($table)->whereIn('id', $ids)->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]);
            foreach ($rows as $row) {
                $row->{Str::beforeLast($column, '_id').'_label'} = $row->{$column} ? ($labels[$row->{$column}] ?? null) : null;
            }
        }
    }

    public function show(string $id, bool $canSeeBuy): array
    {
        $table = $this->store->find('rate_tables', $id);
        abort_if($table->side === 'BUY' && ! $canSeeBuy, 404);
        $rows = collect([$table]);
        $this->annotate($rows, self::TABLE_REFS);
        $lines = $this->store->query('rate_lines')->where('rate_table_id', $id)->orderBy('charge_code_id')->orderBy('created_at')->get();
        $this->annotate($lines, self::LINE_REFS);
        if ($table->side === 'BUY') {
            $margins = $this->store->query('rate_line_margins')->whereIn('rate_line_id', $lines->pluck('id'))->get();
            $this->annotate($margins, ['customer_id' => 'customers', 'project_id' => 'customer_projects']);
            foreach ($lines as $line) {
                $line->margins = $margins->where('rate_line_id', $line->id)->values()->all();
            }
        }
        $versions = $this->store->query('rate_tables')->where('code', $table->code)->orderByDesc('version')->get(['id', 'version', 'status', 'valid_from', 'valid_until']);

        return ['table' => $table, 'lines' => $lines->all(), 'versions' => $versions->all()];
    }

    private function tableRules(bool $creating): array
    {
        $rules = [
            'name' => 'required|string|max:200',
            'customer_id' => ['nullable', 'uuid', $this->exists('customers')],
            'project_id' => ['nullable', 'uuid', $this->exists('customer_projects')],
            'vendor_id' => ['nullable', 'uuid', $this->exists('vendors')],
            'valid_from' => 'required|date_format:Y-m-d',
            'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'notes' => 'nullable|string|max:2000',
        ];
        if ($creating) {
            $rules += ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'side' => 'required|in:SELL,BUY'];
        }

        return $rules;
    }

    private function checkParty(string $side, array $d): void
    {
        if ($side === 'SELL' && ! empty($d['vendor_id'])) {
            $this->fail('vendor_id', 'Tarif jual tidak memiliki vendor.');
        }
        if ($side === 'BUY' && (! empty($d['customer_id']) || ! empty($d['project_id']))) {
            $this->fail('customer_id', 'Tarif beli tidak terkait pelanggan atau proyek.');
        }
        if (! empty($d['project_id'])) {
            if (empty($d['customer_id'])) {
                $this->fail('customer_id', 'Pilih pelanggan untuk proyek ini.');
            }
            $project = $this->store->query('customer_projects')->where('id', $d['project_id'])->first();
            if ($project->customer_id !== $d['customer_id']) {
                $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
            }
        }
    }

    private function tableData(array $input, bool $creating): array
    {
        $d = Validator::make($input, $this->tableRules($creating), [], ['name' => 'Nama', 'valid_from' => 'Berlaku dari', 'valid_until' => 'Berlaku sampai', 'code' => 'Kode', 'side' => 'Sisi'])->validate();
        foreach (array_keys(self::TABLE_REFS) as $column) {
            $d[$column] = $d[$column] ?? null;
        }
        if ($creating) {
            $d['code'] = mb_strtoupper($d['code']);
        }

        return $d;
    }

    public function create(array $input): object
    {
        $d = $this->tableData($input, true);
        $this->checkParty($d['side'], $d);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if ($this->store->query('rate_tables')->where('code', $d['code'])->exists()) {
                $this->fail('code', 'Kode tarif sudah dipakai. Buat versi baru dari tarif yang ada.');
            }
            $id = (string) Str::uuid();
            DB::table('rate_tables')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'version' => 1, 'status' => 'DRAFT', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('tariff.created', $id, ['code' => $d['code'], 'version' => 1, 'side' => $d['side']]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    private function draft(string $id, bool $review = false): object
    {
        $table = $this->store->find('rate_tables', $id, true);
        abort_unless($table->status === 'DRAFT', 409, 'Hanya draf yang dapat diubah. Buat versi baru untuk mengubah tarif yang sudah disetujui.');
        if (! $review) {
            // What a vendor proposed is the vendor's to change; the tenant approves it or sends it back, it does not edit it.
            abort_if($table->proposed_by_vendor && ! $this->asVendor, 409, 'Tarif ini disusun vendor: setujui atau tolak, jangan ubah.');
            abort_if(! $table->proposed_by_vendor && $this->asVendor, 403, 'Tarif ini bukan usulan vendor.');
            abort_if($this->asVendor && $table->submitted_at !== null, 409, 'Tarif sudah diajukan; tarik kembali untuk mengubahnya.');
        }

        return $table;
    }

    public function update(string $id, array $input): object
    {
        return DB::transaction(function () use ($id, $input) {
            $table = $this->draft($id);
            $d = $this->tableData($input, false);
            $this->checkParty($table->side, $d);
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->store->event('tariff.updated', $id, ['code' => $table->code, 'version' => $table->version]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    // ---------------------------------------------------------------- lines

    private function lineData(object $table, array $input): array
    {
        $rules = ['basis' => ['required', Rule::in(array_keys(self::BASES))], 'unit_price_minor' => 'required|integer|min:0|max:100000000000000', 'min_charge_minor' => 'nullable|integer|min:0|max:100000000000000', 'free_time_hours' => 'nullable|integer|min:0|max:8760', 'notes' => 'nullable|string|max:300'];
        foreach (self::LINE_REFS as $column => $master) {
            $rules[$column] = [$column === 'charge_code_id' ? 'required' : 'nullable', 'uuid', $this->exists($master)];
        }
        $d = Validator::make($input, $rules, [], ['basis' => 'Dasar tarif', 'unit_price_minor' => 'Tarif', 'min_charge_minor' => 'Biaya minimum', 'charge_code_id' => 'Kode biaya'])->validate();
        foreach (array_keys(self::LINE_REFS) as $column) {
            $d[$column] = $d[$column] ?? null;
        }
        $this->mapping($table, $input, $d);
        $d['min_charge_minor'] = $d['min_charge_minor'] ?? 0;
        $d['free_time_hours'] = $d['free_time_hours'] ?? null;
        $d['notes'] = $d['notes'] ?? null;
        if (count(array_filter([$d['origin_location_id'], $d['origin_city_id'], $d['origin_zone_id']])) > 1) {
            $this->fail('origin_location_id', 'Pilih salah satu: lokasi, kota, atau zona asal.');
        }
        if (count(array_filter([$d['destination_location_id'], $d['destination_city_id'], $d['destination_zone_id']])) > 1) {
            $this->fail('destination_location_id', 'Pilih salah satu: lokasi, kota, atau zona tujuan.');
        }
        $charge = $this->store->query('charge_codes')->where('id', $d['charge_code_id'])->first();
        if (! $charge->active) {
            $this->fail('charge_code_id', 'Kode biaya tidak aktif.');
        }
        if ($charge->side !== 'BOTH' && $charge->side !== $table->side) {
            $this->fail('charge_code_id', 'Kode biaya ini hanya untuk sisi '.($charge->side === 'SELL' ? 'jual' : 'beli').'.');
        }

        return $d;
    }

    /**
     * Unit and margin of a mapping row. The unit goes with the basis UNIT and with nothing else; the margin exists only on a BUY
     * line, never on a vendor's own proposal, and turns the initial rate into the base tariff, which is computed here.
     */
    private function mapping(object $table, array $input, array &$d): void
    {
        $unit = ($d['basis'] ?? null) === 'UNIT';
        if ($unit && empty($d['unit_id'])) {
            $this->fail('unit_id', 'Pilih satuan muatan untuk dasar tarif per satuan.');
        }
        if (! $unit && ! empty($d['unit_id'])) {
            $this->fail('unit_id', 'Satuan hanya dipakai pada dasar tarif per satuan muatan.');
        }
        $mode = $input['margin_mode'] ?? null;
        $value = $input['margin_value'] ?? null;
        if (($mode === null || $mode === '') && ($value === null || $value === '')) {
            $d['margin_mode'] = $d['margin_value'] = $d['sell_unit_price_minor'] = null;

            return;
        }
        if ($table->side !== 'BUY' || $this->asVendor) {
            $this->fail('margin_mode', 'Margin hanya untuk baris tarif beli milik tenant.');
        }
        $m = $this->margin($mode, $value);
        $d['margin_mode'] = $m['margin_mode'];
        $d['margin_value'] = $m['margin_value'];
        $d['sell_unit_price_minor'] = BaseTariff::price((int) $d['unit_price_minor'], $m['margin_mode'], $m['margin_value']);
    }

    /** @return array{margin_mode: string, margin_value: int} */
    private function margin(mixed $mode, mixed $value): array
    {
        $m = Validator::make(['margin_mode' => $mode, 'margin_value' => $value], ['margin_mode' => ['required', Rule::in(BaseTariff::MODES)], 'margin_value' => 'required|integer|min:0|max:100000000000000'], [], ['margin_mode' => 'Jenis margin', 'margin_value' => 'Nilai margin'])->validate();
        if ($m['margin_mode'] === 'PERCENT' && $m['margin_value'] > 100000) {
            $this->fail('margin_value', 'Margin persen terlalu besar (maksimum 1000%).');
        }

        return $m;
    }

    /** Sets (or replaces) the margin of a draft BUY line for one customer, or one project of it. */
    public function setLineMargin(string $tableId, string $lineId, array $input): object
    {
        return DB::transaction(function () use ($tableId, $lineId, $input) {
            $table = $this->draft($tableId);
            if ($table->side !== 'BUY' || $this->asVendor) {
                $this->fail('customer_id', 'Margin per pelanggan hanya untuk baris tarif beli milik tenant.');
            }
            $line = $this->store->query('rate_lines')->where('rate_table_id', $tableId)->where('id', $lineId)->lockForUpdate()->firstOrFail();
            $d = Validator::make($input, ['customer_id' => ['required', 'uuid', $this->exists('customers')], 'project_id' => ['nullable', 'uuid', $this->exists('customer_projects')]], [], ['customer_id' => 'Pelanggan', 'project_id' => 'Proyek'])->validate();
            if (! empty($d['project_id']) && $this->store->query('customer_projects')->where('id', $d['project_id'])->value('customer_id') !== $d['customer_id']) {
                $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
            }
            $m = $this->margin($input['margin_mode'] ?? null, $input['margin_value'] ?? null);
            $project = $d['project_id'] ?? null;
            $existing = $this->store->query('rate_line_margins')->where('rate_line_id', $lineId)->where('customer_id', $d['customer_id'])
                ->when($project === null, fn ($q) => $q->whereNull('project_id'), fn ($q) => $q->where('project_id', $project))->first();
            $id = $existing->id ?? (string) Str::uuid();
            if ($existing) {
                DB::table('rate_line_margins')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($m + ['updated_at' => now()]);
            } else {
                DB::table('rate_line_margins')->insert($m + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'rate_line_id' => $lineId, 'customer_id' => $d['customer_id'], 'project_id' => $project, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->store->event('tariff.line.margin_set', $tableId, ['code' => $table->code, 'version' => $table->version, 'line_id' => $line->id, 'customer_id' => $d['customer_id'], 'project_id' => $project, 'margin_mode' => $m['margin_mode'], 'margin_value' => $m['margin_value']]);

            return $this->store->find('rate_line_margins', $id);
        }, 3);
    }

    public function removeLineMargin(string $tableId, string $lineId, string $marginId): void
    {
        DB::transaction(function () use ($tableId, $lineId, $marginId) {
            $table = $this->draft($tableId);
            $this->store->query('rate_lines')->where('rate_table_id', $tableId)->where('id', $lineId)->firstOrFail();
            $margin = $this->store->query('rate_line_margins')->where('rate_line_id', $lineId)->where('id', $marginId)->firstOrFail();
            DB::table('rate_line_margins')->where('tenant_id', $this->store->tenantId)->where('id', $marginId)->delete();
            $this->store->event('tariff.line.margin_removed', $tableId, ['code' => $table->code, 'version' => $table->version, 'line_id' => $lineId, 'customer_id' => $margin->customer_id, 'project_id' => $margin->project_id]);
        }, 3);
    }

    /** The line of a table with the same lane, carrier, service, charge code and basis, if there is one. */
    public function findLine(string $tableId, array $d, ?string $except = null): ?object
    {
        $query = $this->store->query('rate_lines')->where('rate_table_id', $tableId)->where('basis', $d['basis']);
        foreach (array_keys(self::LINE_REFS) as $column) {
            ($d[$column] ?? null) === null ? $query->whereNull($column) : $query->where($column, $d[$column]);
        }
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        return $query->first();
    }

    private function assertNoDuplicate(string $tableId, array $d, ?string $except): void
    {
        if ($this->findLine($tableId, $d, $except) !== null) {
            $this->fail('charge_code_id', 'Baris dengan rute, armada, layanan, kode biaya, dan dasar yang sama sudah ada.');
        }
    }

    /**
     * Checks a line of a draft without writing it (used by the import preview).
     *
     * @return array{0: ?array<string, mixed>, 1: array<string, list<string>>} the clean values, or the errors
     */
    public function checkLine(object $table, array $input): array
    {
        try {
            return [$this->lineData($table, $input), []];
        } catch (ValidationException $e) {
            return [null, $e->errors()];
        }
    }

    public function addLine(string $tableId, array $input): object
    {
        return DB::transaction(function () use ($tableId, $input) {
            $table = $this->draft($tableId);
            $d = $this->lineData($table, $input);
            $this->assertNoDuplicate($tableId, $d, null);
            $id = (string) Str::uuid();
            DB::table('rate_lines')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'rate_table_id' => $tableId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('tariff.line.added', $tableId, ['code' => $table->code, 'version' => $table->version, 'line_id' => $id, 'unit_price_minor' => $d['unit_price_minor']]);

            return $this->store->find('rate_lines', $id);
        }, 3);
    }

    public function updateLine(string $tableId, string $lineId, array $input): object
    {
        return DB::transaction(function () use ($tableId, $lineId, $input) {
            $table = $this->draft($tableId);
            $before = $this->store->query('rate_lines')->where('rate_table_id', $tableId)->where('id', $lineId)->lockForUpdate()->firstOrFail();
            $d = $this->lineData($table, $input);
            $this->assertNoDuplicate($tableId, $d, $lineId);
            DB::table('rate_lines')->where('tenant_id', $this->store->tenantId)->where('id', $lineId)->update($d + ['updated_at' => now()]);
            $this->store->event('tariff.line.updated', $tableId, ['code' => $table->code, 'version' => $table->version, 'line_id' => $lineId, 'from' => $before->unit_price_minor, 'to' => $d['unit_price_minor']]);

            return $this->store->find('rate_lines', $lineId);
        }, 3);
    }

    public function deleteLine(string $tableId, string $lineId): void
    {
        DB::transaction(function () use ($tableId, $lineId) {
            $table = $this->draft($tableId);
            $line = $this->store->query('rate_lines')->where('rate_table_id', $tableId)->where('id', $lineId)->firstOrFail();
            DB::table('rate_lines')->where('tenant_id', $this->store->tenantId)->where('id', $lineId)->delete();
            $this->store->event('tariff.line.removed', $tableId, ['code' => $table->code, 'version' => $table->version, 'line_id' => $lineId, 'unit_price_minor' => $line->unit_price_minor]);
        }, 3);
    }

    // ---------------------------------------------------------------- lifecycle

    /** Approves a draft. The approver is never its author; an earlier approved version of the tariff ends the day before this one starts. */
    public function approve(string $id): object
    {
        return DB::transaction(function () use ($id) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $table = $this->draft($id, true);
            abort_if($table->created_by === $this->store->actorId, 403, 'Tarif harus disetujui oleh orang lain, bukan penyusunnya.');
            abort_if($table->proposed_by_vendor && $table->submitted_at === null, 409, 'Vendor belum mengajukan tarif ini.');
            abort_unless($this->store->query('rate_lines')->where('rate_table_id', $id)->exists(), 422, 'Tarif belum memiliki baris.');
            $others = $this->store->query('rate_tables')->where('code', $table->code)->where('status', 'APPROVED')->where('id', '!=', $id)->get();
            $from = Carbon::parse($table->valid_from);
            foreach ($others as $o) {
                if (Carbon::parse($o->valid_from)->gte($from)) {
                    abort(422, 'Periode tarif tumpang tindih dengan versi '.$o->version.' yang sudah disetujui dan berlaku lebih akhir.');
                }
            }
            foreach ($others as $o) {
                if ($o->valid_until === null || Carbon::parse($o->valid_until)->gte($from)) {
                    DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $o->id)->update(['valid_until' => $from->copy()->subDay()->toDateString(), 'updated_at' => now()]);
                    $this->store->event('tariff.superseded', $o->id, ['code' => $o->code, 'version' => $o->version, 'by_version' => $table->version]);
                }
            }
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $this->store->actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->store->event('tariff.approved', $id, ['code' => $table->code, 'version' => $table->version, 'side' => $table->side, 'created_by' => $table->created_by]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    /** Sends a vendor's submitted proposal back to the vendor with the reason; the vendor can then change and submit it again. */
    public function rejectProposal(string $id, string $note): object
    {
        if (mb_strlen(trim($note)) < 5) {
            $this->fail('note', 'Alasan penolakan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $note) {
            $table = $this->draft($id, true);
            abort_unless($table->proposed_by_vendor && $table->submitted_at !== null, 409, 'Hanya tarif yang diajukan vendor yang dapat ditolak.');
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['submitted_at' => null, 'review_note' => $note, 'updated_at' => now()]);
            $this->store->event('tariff.proposal_rejected', $id, ['code' => $table->code, 'version' => $table->version, 'vendor_id' => $table->vendor_id, 'note' => $note]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    public function retire(string $id, string $reason): object
    {
        return DB::transaction(function () use ($id, $reason) {
            $table = $this->store->find('rate_tables', $id, true);
            abort_unless($table->status === 'APPROVED', 409, 'Hanya tarif yang sudah disetujui yang dapat dihentikan.');
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'RETIRED', 'status_reason' => $reason, 'updated_at' => now()]);
            $this->store->event('tariff.retired', $id, ['code' => $table->code, 'version' => $table->version, 'reason' => $reason]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    /** Copies the latest version of a tariff into the next draft, starting on $validFrom. */
    public function newVersion(string $id, string $validFrom): object
    {
        Validator::make(['valid_from' => $validFrom], ['valid_from' => 'required|date_format:Y-m-d'], [], ['valid_from' => 'Berlaku dari'])->validate();

        return DB::transaction(function () use ($id, $validFrom) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $source = $this->store->find('rate_tables', $id);
            abort_if($source->status === 'DRAFT', 409, 'Tarif ini masih draf.');
            $latest = (int) $this->store->query('rate_tables')->where('code', $source->code)->max('version');
            abort_unless($source->version === $latest, 409, 'Buat versi baru dari versi terakhir tarif ini.');
            $id2 = (string) Str::uuid();
            DB::table('rate_tables')->insert([
                'id' => $id2, 'tenant_id' => $this->store->tenantId, 'code' => $source->code, 'version' => $latest + 1, 'name' => $source->name, 'side' => $source->side,
                'customer_id' => $source->customer_id, 'project_id' => $source->project_id, 'vendor_id' => $source->vendor_id, 'valid_from' => $validFrom, 'valid_until' => null,
                'status' => 'DRAFT', 'notes' => $source->notes, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($this->store->query('rate_lines')->where('rate_table_id', $id)->get() as $line) {
                $copy = (array) $line;
                $copy['id'] = (string) Str::uuid();
                $copy['rate_table_id'] = $id2;
                $copy['created_at'] = $copy['updated_at'] = now();
                DB::table('rate_lines')->insert($copy);
                foreach ($this->store->query('rate_line_margins')->where('rate_line_id', $line->id)->get() as $margin) {
                    $m = (array) $margin;
                    $m['id'] = (string) Str::uuid();
                    $m['rate_line_id'] = $copy['id'];
                    $m['created_at'] = $m['updated_at'] = now();
                    DB::table('rate_line_margins')->insert($m);
                }
            }
            $this->store->event('tariff.version_created', $id2, ['code' => $source->code, 'version' => $latest + 1, 'from_version' => $source->version]);

            return $this->store->find('rate_tables', $id2);
        }, 3);
    }

    // ---------------------------------------------------------------- markup rules

    public function markups(): array
    {
        $rows = $this->store->query('markup_rules')->orderBy('code')->get();
        $this->annotate($rows, ['customer_id' => 'customers', 'project_id' => 'customer_projects', 'carrier_type_id' => 'carrier_types', 'service_id' => 'services', 'origin_city_id' => 'cities', 'destination_city_id' => 'cities']);

        return $rows->all();
    }

    private function markupData(array $input, bool $creating): array
    {
        $rules = [
            'name' => 'required|string|max:200', 'mode' => 'required|in:PERCENT,AMOUNT',
            'percent_bps' => 'nullable|required_if:mode,PERCENT|integer|min:0|max:100000', 'amount_minor' => 'nullable|required_if:mode,AMOUNT|integer|min:0|max:100000000000000',
            'customer_id' => ['nullable', 'uuid', $this->exists('customers')], 'project_id' => ['nullable', 'uuid', $this->exists('customer_projects')],
            'carrier_type_id' => ['nullable', 'uuid', $this->exists('carrier_types')], 'service_id' => ['nullable', 'uuid', $this->exists('services')],
            'origin_city_id' => ['nullable', 'uuid', $this->exists('cities')], 'destination_city_id' => ['nullable', 'uuid', $this->exists('cities')],
            'valid_from' => 'nullable|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
        ];
        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'];
        }
        $d = Validator::make($input, $rules, [], ['name' => 'Nama', 'mode' => 'Jenis markup', 'percent_bps' => 'Persentase', 'amount_minor' => 'Nominal', 'code' => 'Kode'])->validate();
        foreach (['percent_bps', 'amount_minor', 'customer_id', 'project_id', 'carrier_type_id', 'service_id', 'origin_city_id', 'destination_city_id', 'valid_from', 'valid_until'] as $column) {
            $d[$column] = $d[$column] ?? null;
        }
        if ($d['mode'] === 'PERCENT') {
            $d['amount_minor'] = null;
        } else {
            $d['percent_bps'] = null;
        }
        if ($d['project_id'] !== null) {
            $project = $this->store->query('customer_projects')->where('id', $d['project_id'])->first();
            if ($d['customer_id'] !== null && $project->customer_id !== $d['customer_id']) {
                $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
            }
            $d['customer_id'] ??= $project->customer_id;
        }
        if ($creating) {
            $d['code'] = mb_strtoupper($d['code']);
        }

        return $d;
    }

    public function createMarkup(array $input): object
    {
        $d = $this->markupData($input, true);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if ($this->store->query('markup_rules')->where('code', $d['code'])->exists()) {
                $this->fail('code', 'Kode markup sudah dipakai.');
            }
            $id = (string) Str::uuid();
            DB::table('markup_rules')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'active' => true, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('tariff.markup.created', $id, ['code' => $d['code'], 'mode' => $d['mode'], 'percent_bps' => $d['percent_bps'], 'amount_minor' => $d['amount_minor']]);

            return $this->store->find('markup_rules', $id);
        }, 3);
    }

    public function updateMarkup(string $id, array $input): object
    {
        $d = $this->markupData($input, false);

        return DB::transaction(function () use ($id, $d) {
            $before = $this->store->find('markup_rules', $id, true);
            DB::table('markup_rules')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->store->event('tariff.markup.updated', $id, ['code' => $before->code, 'from' => ['percent_bps' => $before->percent_bps, 'amount_minor' => $before->amount_minor], 'to' => ['percent_bps' => $d['percent_bps'], 'amount_minor' => $d['amount_minor']]]);

            return $this->store->find('markup_rules', $id);
        }, 3);
    }

    public function setMarkupActive(string $id, bool $active): object
    {
        return DB::transaction(function () use ($id, $active) {
            $before = $this->store->find('markup_rules', $id, true);
            if ((bool) $before->active !== $active) {
                DB::table('markup_rules')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
                $this->store->event($active ? 'tariff.markup.activated' : 'tariff.markup.deactivated', $id, ['code' => $before->code]);
            }

            return $this->store->find('markup_rules', $id);
        }, 3);
    }
}
