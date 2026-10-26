<?php

namespace App\Domain\Contract;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fixed-cost vehicle contracts. A contract dedicates a vehicle to a customer for a period: a monthly selling fee that includes some
 * trips, a price per extra trip, and the matching buying side (the vehicle's own monthly cost, for instance a vendor rental). A monthly
 * run prorates the fees by the vehicle's attendance (present or on standby days over the working days of the contract, or by the calendar
 * when no attendance was recorded), counts the trips the vehicle completed for that customer in the month, and writes selling and buying
 * charges. They are verified by someone other than the person who ran the month, billed with the SPM charges and counted in the P&L.
 */
final class ContractService
{
    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function exists(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->store->tenantId);
    }

    // ---------------------------------------------------------------- contracts

    /** @return list<object> */
    public function list(?string $status): array
    {
        $query = $this->store->query('fixcost_contracts');
        if (in_array($status, ['ACTIVE', 'ENDED'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->orderBy('code')->get();
        $customers = $this->store->query('customers')->pluck('name', 'id');
        $plates = $this->store->query('vehicles')->pluck('plate', 'id');
        $vendors = $this->store->query('vendors')->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->customer_label = $customers[$row->customer_id] ?? null;
            $row->vehicle_label = $plates[$row->vehicle_id] ?? null;
            $row->vendor_label = $row->vendor_id ? ($vendors[$row->vendor_id] ?? null) : null;
        }

        return $rows->all();
    }

    public function show(string $id): array
    {
        $contract = $this->store->find('fixcost_contracts', $id);
        $charges = $this->store->query('contract_charges')->where('contract_id', $id)->orderByDesc('period')->orderBy('side')->orderBy('kind')->get();

        return ['contract' => $contract, 'charges' => $charges->all()];
    }

    private function rules(bool $creating): array
    {
        $rules = [
            'name' => 'required|string|max:200', 'notes' => 'nullable|string|max:1000',
            'project_id' => ['nullable', 'uuid', $this->exists('customer_projects')], 'vendor_id' => ['nullable', 'uuid', $this->exists('vendors')],
            'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'monthly_sell_minor' => 'required|integer|min:0|max:100000000000000', 'monthly_buy_minor' => 'nullable|integer|min:0|max:100000000000000',
            'trips_included' => 'nullable|integer|min:0|max:1000', 'overage_sell_minor' => 'nullable|integer|min:0|max:100000000000000', 'overage_buy_minor' => 'nullable|integer|min:0|max:100000000000000',
            'prorate' => 'nullable|boolean', 'working_days' => 'nullable|integer|min:1|max:31',
        ];
        if ($creating) {
            $rules += ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'customer_id' => ['required', 'uuid', $this->exists('customers')], 'vehicle_id' => ['required', 'uuid', $this->exists('vehicles')]];
        }

        return $rules;
    }

    private function data(array $input, bool $creating): array
    {
        $d = Validator::make($input, $this->rules($creating), [], [
            'name' => 'Nama', 'code' => 'Kode', 'customer_id' => 'Pelanggan', 'vehicle_id' => 'Kendaraan', 'valid_from' => 'Berlaku dari', 'valid_until' => 'Berlaku sampai', 'monthly_sell_minor' => 'Biaya jual bulanan',
        ])->validate();
        foreach (['project_id' => null, 'vendor_id' => null, 'valid_until' => null, 'notes' => null, 'monthly_buy_minor' => 0, 'trips_included' => 0, 'overage_sell_minor' => 0, 'overage_buy_minor' => 0, 'prorate' => true, 'working_days' => 26] as $key => $default) {
            $d[$key] = $d[$key] ?? $default;
        }
        if ($creating) {
            $d['code'] = mb_strtoupper($d['code']);
        }

        return $d;
    }

    private function assertConsistent(array $d, ?string $customerId, string $vehicleId, ?string $exceptId): void
    {
        if ($d['project_id'] !== null && $this->store->query('customer_projects')->where('id', $d['project_id'])->value('customer_id') !== $customerId) {
            $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
        }
        $clash = $this->store->query('fixcost_contracts')->where('vehicle_id', $vehicleId)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $d['valid_from']))
            ->when($d['valid_until'], fn ($q) => $q->where('valid_from', '<=', $d['valid_until']))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();
        if ($clash) {
            $this->fail('vehicle_id', "Kendaraan ini sudah dikontrakkan di kontrak {$clash->code} pada periode yang beririsan.");
        }
    }

    public function create(array $input): array
    {
        $d = $this->data($input, true);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if ($this->store->query('fixcost_contracts')->where('code', $d['code'])->exists()) {
                $this->fail('code', 'Kode kontrak sudah dipakai.');
            }
            $this->assertConsistent($d, $d['customer_id'], $d['vehicle_id'], null);
            $id = (string) Str::uuid();
            DB::table('fixcost_contracts')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'status' => 'ACTIVE', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('contract.created', $id, ['code' => $d['code'], 'monthly_sell_minor' => $d['monthly_sell_minor']]);

            return $this->show($id);
        }, 3);
    }

    public function update(string $id, array $input): array
    {
        $d = $this->data($input, false);

        return DB::transaction(function () use ($id, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $contract = $this->store->find('fixcost_contracts', $id, true);
            abort_unless($contract->status === 'ACTIVE', 409, 'Kontrak yang sudah berakhir tidak dapat diubah.');
            if ($this->store->query('contract_charges')->where('contract_id', $id)->where('status', 'VERIFIED')->exists()
                && ((int) $contract->monthly_sell_minor !== $d['monthly_sell_minor'] || (int) $contract->monthly_buy_minor !== $d['monthly_buy_minor'] || $d['valid_from'] !== substr((string) $contract->valid_from, 0, 10))) {
                $this->fail('monthly_sell_minor', 'Biaya dan tanggal mulai tidak dapat diubah setelah ada biaya terverifikasi; akhiri kontrak dan buat yang baru.');
            }
            $this->assertConsistent($d, $contract->customer_id, $contract->vehicle_id, $id);
            DB::table('fixcost_contracts')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->store->event('contract.updated', $id, ['code' => $contract->code]);

            return $this->show($id);
        }, 3);
    }

    public function end(string $id, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason) {
            $contract = $this->store->find('fixcost_contracts', $id, true);
            abort_unless($contract->status === 'ACTIVE', 409, 'Kontrak sudah berakhir.');
            $until = $contract->valid_until === null || CarbonImmutable::parse($contract->valid_until)->isFuture() ? now()->toDateString() : substr((string) $contract->valid_until, 0, 10);
            DB::table('fixcost_contracts')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'ENDED', 'valid_until' => max($until, substr((string) $contract->valid_from, 0, 10)), 'notes' => trim(($contract->notes ? $contract->notes."\n" : '').'Berakhir: '.$reason), 'updated_at' => now()]);
            $this->store->event('contract.ended', $id, ['code' => $contract->code, 'reason' => $reason]);

            return $this->show($id);
        });
    }

    // ---------------------------------------------------------------- attendance

    /** Marks a vehicle for a range of days (at most 62); a day already recorded is overwritten. @return int days written */
    public function attend(array $input): int
    {
        $d = Validator::make($input, [
            'vehicle_id' => ['required', 'uuid', $this->exists('vehicles')], 'driver_id' => ['nullable', 'uuid', $this->exists('drivers')],
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'status' => 'required|in:PRESENT,STANDBY,ABSENT,OFF', 'notes' => 'nullable|string|max:300',
        ], [], ['from' => 'Dari', 'to' => 'Sampai'])->validate();
        $from = CarbonImmutable::parse($d['from']);
        $to = CarbonImmutable::parse($d['to']);
        if ($from->diffInDays($to) > 61) {
            $this->fail('to', 'Rentang kehadiran maksimal 62 hari.');
        }
        if ($to->isFuture()) {
            $this->fail('to', 'Kehadiran tidak dapat dicatat untuk tanggal yang belum terjadi.');
        }

        return DB::transaction(function () use ($d, $from, $to) {
            $count = 0;
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                DB::table('vehicle_attendance')->upsert([[
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'vehicle_id' => $d['vehicle_id'], 'driver_id' => $d['driver_id'] ?? null, 'work_date' => $day->toDateString(),
                    'status' => $d['status'], 'notes' => $d['notes'] ?? null, 'recorded_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
                ]], ['tenant_id', 'vehicle_id', 'work_date'], ['driver_id', 'status', 'notes', 'recorded_by', 'updated_at']);
                $count++;
            }
            $this->store->event('attendance.recorded', $d['vehicle_id'], ['from' => $d['from'], 'to' => $d['to'], 'status' => $d['status'], 'days' => $count]);

            return $count;
        });
    }

    /** @return list<object> */
    public function attendance(string $vehicleId, string $period): array
    {
        Validator::make(['period' => $period], ['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])->validate();
        $start = CarbonImmutable::parse($period.'-01');

        return $this->store->query('vehicle_attendance')->where('vehicle_id', $vehicleId)->whereBetween('work_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])->orderBy('work_date')->get()->all();
    }

    // ---------------------------------------------------------------- the monthly run

    /** @return array{contract: object, charges: list<object>, warnings: list<string>} */
    public function run(string $contractId, string $period): array
    {
        Validator::make(['period' => $period], ['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']], [], ['period' => 'Periode'])->validate();

        return DB::transaction(function () use ($contractId, $period) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $contract = $this->store->find('fixcost_contracts', $contractId, true);
            $monthStart = CarbonImmutable::parse($period.'-01');
            if ($monthStart->isFuture()) {
                $this->fail('period', 'Periode belum dimulai.');
            }
            $from = $monthStart->max(CarbonImmutable::parse($contract->valid_from));
            $to = $monthStart->endOfMonth()->min($contract->valid_until ? CarbonImmutable::parse($contract->valid_until) : $monthStart->endOfMonth());
            if ($from->gt($to)) {
                $this->fail('period', 'Kontrak tidak berlaku pada periode ini.');
            }
            if ($this->store->query('contract_charges')->where('contract_id', $contractId)->where('period', $period)->where('status', 'VERIFIED')->exists()) {
                abort(409, 'Biaya periode ini sudah diverifikasi; tidak dapat dihitung ulang.');
            }
            $warnings = [];
            $attendance = $this->store->query('vehicle_attendance')->where('vehicle_id', $contract->vehicle_id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
            $billable = $attendance->whereIn('status', ['PRESENT', 'STANDBY'])->count();
            $factor = 1.0;
            $basis = 'FULL';
            if ($contract->prorate) {
                if ($attendance->isNotEmpty()) {
                    $factor = min(1.0, $billable / max(1, (int) $contract->working_days));
                    $basis = 'ATTENDANCE';
                } else {
                    $factor = ((int) round($from->startOfDay()->diffInDays($to->startOfDay())) + 1) / $monthStart->daysInMonth;
                    $basis = $factor < 1 ? 'CALENDAR' : 'FULL';
                    $warnings[] = 'Kehadiran kendaraan belum dicatat pada periode ini'.($factor < 1 ? '; biaya dihitung menurut hari kontrak berlaku.' : '; biaya dihitung penuh.');
                }
            }
            $trips = $this->store->query('spm_trips')->where('vehicle_id', $contract->vehicle_id)->where('status', 'COMPLETED')
                ->where('completed_at', '>=', $from->startOfDay())->where('completed_at', '<', $to->addDay()->startOfDay())
                ->whereIn('transport_order_id', $this->store->query('transport_orders')->where('customer_id', $contract->customer_id)->when($contract->project_id, fn ($q) => $q->where('project_id', $contract->project_id))->select('id'))->count();
            $extra = max(0, $trips - (int) $contract->trips_included);
            $calculation = ['period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => $basis, 'factor' => round($factor, 6), 'working_days' => (int) $contract->working_days, 'billable_days' => $billable, 'attendance_days' => $attendance->count(), 'trips' => $trips, 'trips_included' => (int) $contract->trips_included, 'extra_trips' => $extra];

            $this->store->query('contract_charges')->where('contract_id', $contractId)->where('period', $period)->where('status', 'PROPOSED')->delete();
            $label = $monthStart->locale('id')->translatedFormat('F Y');
            $rows = [];
            foreach (['SELL', 'BUY'] as $side) {
                $monthly = (int) $contract->{'monthly_'.strtolower($side).'_minor'};
                $over = (int) $contract->{'overage_'.strtolower($side).'_minor'};
                if ($monthly > 0) {
                    $rows[] = ['side' => $side, 'kind' => 'FIXED', 'description' => ($side === 'SELL' ? 'Sewa kendaraan' : 'Biaya kendaraan')." — kontrak {$contract->code}, {$label}".($factor < 1 ? ' (pro-rata '.round($factor * 100, 1).'%)' : ''), 'quantity' => round($factor, 3), 'unit_price_minor' => $monthly, 'amount_minor' => (int) round($monthly * $factor)];
                }
                if ($extra > 0 && $over > 0) {
                    $rows[] = ['side' => $side, 'kind' => 'OVERAGE', 'description' => "Trip tambahan — kontrak {$contract->code}, {$label} ({$extra} trip)", 'quantity' => $extra, 'unit_price_minor' => $over, 'amount_minor' => $extra * $over];
                }
            }
            if ($rows === []) {
                $warnings[] = 'Tidak ada biaya yang dihasilkan untuk periode ini.';
            }
            foreach ($rows as $row) {
                DB::table('contract_charges')->insert($row + [
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'contract_id' => $contractId, 'period' => $period, 'calculation' => json_encode($calculation, JSON_THROW_ON_ERROR),
                    'status' => 'PROPOSED', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->store->event('contract.run', $contractId, ['code' => $contract->code, 'period' => $period] + $calculation);

            return ['contract' => $contract, 'charges' => $this->store->query('contract_charges')->where('contract_id', $contractId)->where('period', $period)->orderBy('side')->orderBy('kind')->get()->all(), 'warnings' => $warnings];
        }, 3);
    }

    /**
     * @param  list<string>|null  $chargeIds
     * @return array{decided: int, total_minor: int, status: string}
     */
    public function verify(string $contractId, string $period, string $side, string $decision, ?array $chargeIds, ?string $note): array
    {
        if (! in_array($side, ['SELL', 'BUY'], true) || ! in_array($decision, ['VERIFY', 'REJECT'], true)) {
            $this->fail('decision', 'Keputusan atau sisi tidak dikenal.');
        }
        if ($decision === 'REJECT' && mb_strlen(trim((string) $note)) < 5) {
            $this->fail('note', 'Alasan penolakan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($contractId, $period, $side, $decision, $chargeIds, $note) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $contract = $this->store->find('fixcost_contracts', $contractId, true);
            $query = $this->store->query('contract_charges')->where('contract_id', $contractId)->where('period', $period)->where('side', $side)->where('status', 'PROPOSED')->lockForUpdate();
            if ($chargeIds !== null) {
                $query->whereIn('id', $chargeIds);
            }
            $charges = $query->get();
            abort_if($charges->isEmpty(), 422, 'Tidak ada biaya kontrak yang menunggu keputusan.');
            abort_if($charges->contains(fn ($c) => $c->created_by === $this->store->actorId), 403, 'Anda tidak boleh memverifikasi biaya kontrak yang Anda hitung sendiri.');
            $status = $decision === 'VERIFY' ? 'VERIFIED' : 'REJECTED';
            DB::table('contract_charges')->where('tenant_id', $this->store->tenantId)->whereIn('id', $charges->pluck('id'))->update(['status' => $status, 'verified_by' => $this->store->actorId, 'verified_at' => now(), 'verify_note' => $note, 'updated_at' => now()]);
            $total = (int) $charges->sum('amount_minor');
            $this->store->event($decision === 'VERIFY' ? 'contract.charges_verified' : 'contract.charges_rejected', $contractId, ['code' => $contract->code, 'period' => $period, 'side' => $side, 'count' => $charges->count(), 'total_minor' => $total]);

            return ['decided' => $charges->count(), 'total_minor' => $total, 'status' => $status];
        }, 3);
    }
}
