<?php

namespace App\Domain\Tariff;

use App\Domain\Shared\TenantStore;
use App\Domain\Spm\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The buying rates of one vendor, as the vendor sees and proposes them. A rate belongs to a vehicle type (not to a driver): every
 * line names the carrier type it is for. What the vendor proposes is a draft BUY tariff of its own that the tenant approves or sends
 * back; what the tenant entered for the vendor shows here read-only once approved. Nothing is valid as a buying price before approval.
 */
final class VendorRates
{
    /** What a vendor may put on a line. */
    private const LINE_FIELDS = ['carrier_type_id', 'charge_code_id', 'basis', 'unit_price_minor', 'min_charge_minor', 'free_time_hours', 'notes',
        'origin_location_id', 'origin_city_id', 'origin_zone_id', 'destination_location_id', 'destination_city_id', 'destination_zone_id'];

    private TariffService $tariffs;

    public function __construct(private readonly TenantStore $store, private readonly string $vendorId)
    {
        $this->tariffs = (new TariffService($store))->asVendor();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function own(string $id): object
    {
        return $this->store->query('rate_tables')->where('id', $id)->where('side', 'BUY')->where('vendor_id', $this->vendorId)->first() ?? abort(404);
    }

    /** What the vendor may pick from: its vehicle types, the buying charge codes and the places a lane can start or end at. */
    public function options(): array
    {
        $name = fn (string $table, ?callable $scope = null) => ($scope ? $scope($this->store->query($table)) : $this->store->query($table))->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->code.' · '.$r->name])->all();

        return [
            'carrier_types' => $name('carrier_types'),
            'charge_codes' => $name('charge_codes', fn ($q) => $q->whereIn('side', ['BUY', 'BOTH'])),
            'locations' => $name('locations'), 'cities' => $name('cities'), 'zones' => $name('zones'),
            'bases' => TariffService::BASES,
        ];
    }

    public function list(): array
    {
        $rows = $this->store->query('rate_tables')->where('side', 'BUY')->where('vendor_id', $this->vendorId)
            ->where(fn ($q) => $q->where('proposed_by_vendor', true)->orWhereIn('status', ['APPROVED', 'RETIRED']))
            ->orderBy('code')->orderByDesc('version')->get();
        $counts = $this->store->query('rate_lines')->whereIn('rate_table_id', $rows->pluck('id'))->selectRaw('rate_table_id, count(*) as n')->groupBy('rate_table_id')->pluck('n', 'rate_table_id');
        foreach ($rows as $row) {
            $row->line_count = (int) ($counts[$row->id] ?? 0);
            $row->origin = $row->proposed_by_vendor ? 'VENDOR' : 'TENANT';
            $row->state = $this->state($row);
        }

        return $rows->all();
    }

    private function state(object $t): string
    {
        return match (true) {
            $t->status === 'APPROVED' => 'APPROVED', $t->status === 'RETIRED' => 'RETIRED',
            $t->submitted_at !== null => 'SUBMITTED', $t->review_note !== null => 'RETURNED', default => 'DRAFT',
        };
    }

    public function show(string $id): array
    {
        $table = $this->own($id);
        abort_unless($table->proposed_by_vendor || in_array($table->status, ['APPROVED', 'RETIRED'], true), 404);
        $lines = $this->store->query('rate_lines')->where('rate_table_id', $id)->orderBy('carrier_type_id')->orderBy('charge_code_id')->get();
        $this->tariffs->annotate($lines, TariffService::LINE_REFS);
        $table->origin = $table->proposed_by_vendor ? 'VENDOR' : 'TENANT';
        $table->state = $this->state($table);

        return ['table' => $table, 'lines' => $lines->all()];
    }

    public function create(array $input): object
    {
        $d = Validator::make($input, ['name' => 'required|string|max:200', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'notes' => 'nullable|string|max:2000'],
            [], ['name' => 'Nama', 'valid_from' => 'Berlaku dari', 'valid_until' => 'Berlaku sampai'])->validate();

        return DB::transaction(function () use ($d) {
            $vendor = $this->store->find('vendors', $this->vendorId);
            $slug = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $vendor->code), 0, 20) ?: 'V';
            $code = 'VR-'.strtoupper($slug).'-'.sprintf('%04d', DocumentNumbers::next($this->store->tenantId, 'VENDOR_RATE', 'ALL'));
            $table = $this->tariffs->create($d + ['code' => $code, 'side' => 'BUY', 'vendor_id' => $this->vendorId]);
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $table->id)->update(['proposed_by_vendor' => true]);
            $this->store->event('vendor_rate.created', $table->id, ['code' => $code, 'vendor_id' => $this->vendorId]);

            return $this->store->find('rate_tables', $table->id);
        }, 3);
    }

    public function update(string $id, array $input): object
    {
        $this->own($id);
        $d = Validator::make($input, ['name' => 'required|string|max:200', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'notes' => 'nullable|string|max:2000'],
            [], ['name' => 'Nama', 'valid_from' => 'Berlaku dari', 'valid_until' => 'Berlaku sampai'])->validate();

        return $this->tariffs->update($id, $d + ['vendor_id' => $this->vendorId]);
    }

    private function lineInput(array $input): array
    {
        $d = array_intersect_key($input, array_flip(self::LINE_FIELDS));
        if (empty($d['carrier_type_id'])) {
            $this->fail('carrier_type_id', 'Pilih tipe kendaraan untuk tarif ini.');
        }

        return $d;
    }

    public function addLine(string $id, array $input): object
    {
        $this->own($id);

        return $this->tariffs->addLine($id, $this->lineInput($input));
    }

    public function updateLine(string $id, string $line, array $input): object
    {
        $this->own($id);

        return $this->tariffs->updateLine($id, $line, $this->lineInput($input));
    }

    public function deleteLine(string $id, string $line): void
    {
        $this->own($id);
        $this->tariffs->deleteLine($id, $line);
    }

    /** Hands the draft to the tenant. From now on only the tenant acts on it (approve or send back) until the vendor withdraws it. */
    public function submit(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $table = $this->store->find('rate_tables', $id, true);
            $this->own($id);
            abort_unless($table->status === 'DRAFT' && $table->proposed_by_vendor, 409, 'Hanya draf usulan vendor yang dapat diajukan.');
            abort_if($table->submitted_at !== null, 409, 'Tarif sudah diajukan.');
            abort_unless($this->store->query('rate_lines')->where('rate_table_id', $id)->exists(), 422, 'Tarif belum memiliki baris.');
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['submitted_at' => now(), 'review_note' => null, 'updated_at' => now()]);
            $this->store->event('vendor_rate.submitted', $id, ['code' => $table->code, 'vendor_id' => $this->vendorId]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    public function withdraw(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $table = $this->store->find('rate_tables', $id, true);
            $this->own($id);
            abort_unless($table->status === 'DRAFT' && $table->submitted_at !== null, 409, 'Tarif ini tidak sedang diajukan.');
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['submitted_at' => null, 'updated_at' => now()]);
            $this->store->event('vendor_rate.withdrawn', $id, ['code' => $table->code]);

            return $this->store->find('rate_tables', $id);
        }, 3);
    }

    /** A new draft version, to be proposed, of one of the vendor's approved tariffs. */
    public function newVersion(string $id, string $validFrom): object
    {
        return DB::transaction(function () use ($id, $validFrom) {
            $source = $this->own($id);
            abort_unless($source->status === 'APPROVED', 409, 'Versi baru dibuat dari tarif yang sudah disetujui.');
            $table = $this->tariffs->newVersion($id, $validFrom);
            DB::table('rate_tables')->where('tenant_id', $this->store->tenantId)->where('id', $table->id)->update(['proposed_by_vendor' => true, 'submitted_at' => null, 'review_note' => null]);
            $this->store->event('vendor_rate.version_created', $table->id, ['code' => $table->code, 'version' => $table->version]);

            return $this->store->find('rate_tables', $table->id);
        }, 3);
    }
}
