<?php

namespace App\Domain\Cash;

use App\Domain\Shared\TenantStore;
use App\Domain\Spm\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Driver cash (uang jalan). A voucher is requested for a trip with lines per charge code (PLAN when the SPM's tariff already
 * prices that code, otherwise UNPLAN with a reason), approved by someone else (up to a limit unless the approver may approve large
 * amounts), paid out of a cashbox by someone other than the requester, and settled with the actual amount and a receipt for each
 * line. Cash moves only through append-only ledger entries, so the cashbox balance and the cash a driver is expected to hold are
 * sums. Settling turns the part of the spending that the tariff did not already price into BUY charges on the SPM.
 */
final class CashService
{
    public const DEFAULT_LIMIT = 500_000_000;

    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function lock(): void
    {
        DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
    }

    // ---------------------------------------------------------------- settings and cashboxes

    public function limit(): int
    {
        return (int) ($this->store->query('cash_settings')->value('approval_limit_minor') ?? self::DEFAULT_LIMIT);
    }

    public function setLimit(int $minor): int
    {
        Validator::make(['limit' => $minor], ['limit' => 'required|integer|min:100|max:100000000000'], [], ['limit' => 'Batas persetujuan'])->validate();

        return DB::transaction(function () use ($minor) {
            $before = $this->limit();
            DB::table('cash_settings')->upsert([['tenant_id' => $this->store->tenantId, 'approval_limit_minor' => $minor, 'updated_by' => $this->store->actorId, 'updated_at' => now()]], ['tenant_id'], ['approval_limit_minor', 'updated_by', 'updated_at']);
            $this->store->event('cash.settings_changed', $this->store->tenantId, ['from' => $before, 'to' => $minor]);

            return $minor;
        });
    }

    public function cashboxes(): array
    {
        $balances = $this->store->query('cash_ledger')->whereNotNull('cashbox_id')->selectRaw('cashbox_id, sum(amount_minor) as balance')->groupBy('cashbox_id')->pluck('balance', 'cashbox_id');
        $rows = $this->store->query('cashboxes')->orderBy('code')->get();
        foreach ($rows as $row) {
            $row->balance_minor = (int) ($balances[$row->id] ?? 0);
        }

        return $rows->all();
    }

    public function createCashbox(array $input): object
    {
        $d = Validator::make($input, ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'name' => 'required|string|max:200'], [], ['code' => 'Kode', 'name' => 'Nama'])->validate();

        return DB::transaction(function () use ($d) {
            $this->lock();
            $code = mb_strtoupper($d['code']);
            if ($this->store->query('cashboxes')->where('code', $code)->exists()) {
                $this->fail('code', 'Kode kas sudah dipakai.');
            }
            $id = (string) Str::uuid();
            DB::table('cashboxes')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'code' => $code, 'name' => $d['name'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('cashbox.created', $id, ['code' => $code]);

            return $this->store->find('cashboxes', $id);
        });
    }

    public function setCashboxActive(string $id, bool $active): object
    {
        return DB::transaction(function () use ($id, $active) {
            $box = $this->store->find('cashboxes', $id, true);
            DB::table('cashboxes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->store->event($active ? 'cashbox.activated' : 'cashbox.deactivated', $id, ['code' => $box->code]);

            return $this->store->find('cashboxes', $id);
        });
    }

    private function entry(string $type, int $amount, ?string $cashbox, ?string $driver, ?string $voucher, ?string $memo = null): void
    {
        DB::table('cash_ledger')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'cashbox_id' => $cashbox, 'driver_id' => $driver, 'voucher_id' => $voucher,
            'entry_type' => $type, 'amount_minor' => $amount, 'memo' => $memo, 'actor_id' => $this->store->actorId, 'created_at' => now(),
        ]);
    }

    private function cashboxBalance(string $id): int
    {
        return (int) $this->store->query('cash_ledger')->where('cashbox_id', $id)->sum('amount_minor');
    }

    public function topUp(string $cashboxId, int $amount, ?string $memo): object
    {
        Validator::make(['amount' => $amount], ['amount' => 'required|integer|min:100|max:100000000000'], [], ['amount' => 'Jumlah'])->validate();

        return DB::transaction(function () use ($cashboxId, $amount, $memo) {
            $this->lock();
            $box = $this->store->find('cashboxes', $cashboxId, true);
            abort_unless($box->active, 409, 'Kas tidak aktif.');
            $this->entry('TOPUP', $amount, $box->id, null, null, $memo !== null ? mb_substr($memo, 0, 300) : null);
            $this->store->event('cash.topup', $box->id, ['code' => $box->code, 'amount_minor' => $amount]);

            return collect($this->cashboxes())->firstWhere('id', $box->id);
        });
    }

    /** Cash that each driver is expected to hold (disbursed and not yet spent or returned). */
    public function driverBalances(): array
    {
        $rows = $this->store->query('cash_ledger')->whereNotNull('driver_id')->selectRaw('driver_id, sum(amount_minor) as balance')->groupBy('driver_id')->havingRaw('sum(amount_minor) <> 0')->get();
        $names = $this->store->query('drivers')->whereIn('id', $rows->pluck('driver_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => ['driver_id' => $r->driver_id, 'driver_name' => $names[$r->driver_id] ?? null, 'balance_minor' => (int) $r->balance])->sortByDesc('balance_minor')->values()->all();
    }

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function ledger(?string $cashboxId, ?string $driverId, int $page, int $perPage = 50): array
    {
        $query = $this->store->query('cash_ledger');
        if ($cashboxId !== null && Str::isUuid($cashboxId)) {
            $query->where('cashbox_id', $cashboxId);
        } elseif ($driverId !== null && Str::isUuid($driverId)) {
            $query->where('driver_id', $driverId);
        } else {
            abort(422, 'Pilih kas atau pengemudi.');
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('seq')->forPage($page, $perPage)->get();
        $numbers = $this->store->query('cash_vouchers')->whereIn('id', $rows->pluck('voucher_id')->filter())->pluck('number', 'id');
        foreach ($rows as $row) {
            $row->voucher_number = $row->voucher_id ? ($numbers[$row->voucher_id] ?? null) : null;
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    // ---------------------------------------------------------------- vouchers

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function list(?string $status, ?string $search, ?string $tripId, int $page, int $perPage = 50): array
    {
        $query = $this->store->query('cash_vouchers');
        if ($status !== null && in_array($status, ['REQUESTED', 'APPROVED', 'REJECTED', 'DISBURSED', 'SETTLED', 'CANCELLED'], true)) {
            $query->where('status', $status);
        }
        if ($tripId !== null && Str::isUuid($tripId)) {
            $query->where('trip_id', $tripId);
        }
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->whereRaw('lower(number) like ?', [$like]);
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();
        $drivers = $this->store->query('drivers')->whereIn('id', $rows->pluck('driver_id'))->pluck('name', 'id');
        $trips = $this->store->query('spm_trips')->whereIn('id', $rows->pluck('trip_id'))->pluck('number', 'id');
        foreach ($rows as $row) {
            $row->driver_label = $drivers[$row->driver_id] ?? null;
            $row->trip_number = $trips[$row->trip_id] ?? null;
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public function show(string $id): array
    {
        $voucher = $this->store->find('cash_vouchers', $id);
        $voucher->driver_label = $this->store->query('drivers')->where('id', $voucher->driver_id)->value('name');
        $voucher->trip_number = $this->store->query('spm_trips')->where('id', $voucher->trip_id)->value('number');
        $voucher->spm_number = $this->store->query('transport_orders')->where('id', $voucher->transport_order_id)->value('number');
        $voucher->cashbox_label = $voucher->cashbox_id ? $this->store->query('cashboxes')->where('id', $voucher->cashbox_id)->value('name') : null;
        $lines = $this->store->query('cash_voucher_lines')->where('voucher_id', $id)->orderBy('created_at')->get();
        $codes = $this->store->query('charge_codes')->whereIn('id', $lines->pluck('charge_code_id'))->get(['id', 'code', 'name'])->keyBy('id');
        foreach ($lines as $line) {
            $line->charge_code_label = isset($codes[$line->charge_code_id]) ? $codes[$line->charge_code_id]->code.' · '.$codes[$line->charge_code_id]->name : null;
        }
        $entries = $this->store->query('cash_ledger')->where('voucher_id', $id)->orderBy('created_at')->get();

        return ['voucher' => $voucher, 'lines' => $lines->all(), 'ledger' => $entries->all()];
    }

    public function request(string $tripId, array $input): array
    {
        $d = Validator::make($input, [
            'purpose' => 'nullable|string|max:300', 'lines' => 'required|array|min:1|max:30',
            'lines.*.charge_code_id' => ['required', 'uuid', Rule::exists('charge_codes', 'id')->where('tenant_id', $this->store->tenantId)->where('active', true)],
            'lines.*.requested_minor' => 'required|integer|min:100|max:100000000000', 'lines.*.reason' => 'nullable|string|max:500',
        ], [], ['lines.*.requested_minor' => 'Jumlah', 'lines.*.charge_code_id' => 'Kode biaya', 'lines' => 'Rincian'])->validate();

        return DB::transaction(function () use ($tripId, $d) {
            $this->lock();
            $trip = $this->store->find('spm_trips', $tripId, true);
            abort_unless(in_array($trip->status, ['PLANNED', 'IN_TRANSIT'], true), 409, 'Uang jalan hanya dapat diminta untuk perjalanan yang direncanakan atau sedang berjalan.');
            $planned = $this->store->query('spm_charges')->where('transport_order_id', $trip->transport_order_id)->where('side', 'BUY')->where('kind', 'PLAN')->where('source', 'RATE')->where('status', '!=', 'REJECTED')->selectRaw('charge_code_id, sum(amount_minor) as planned')->groupBy('charge_code_id')->pluck('planned', 'charge_code_id');
            $lines = [];
            foreach ($d['lines'] as $i => $line) {
                $plan = $planned[$line['charge_code_id']] ?? null;
                $kind = $plan === null ? 'UNPLAN' : 'PLAN';
                $over = $plan !== null && $line['requested_minor'] > (int) $plan;
                if (($kind === 'UNPLAN' || $over) && mb_strlen(trim((string) ($line['reason'] ?? ''))) < 5) {
                    $this->fail("lines.{$i}.reason", $kind === 'UNPLAN' ? 'Biaya di luar tarif (Unplan) wajib disertai alasan.' : 'Permintaan melebihi nilai tarif; alasan wajib diisi.');
                }
                $lines[] = $line + ['kind' => $kind, 'planned_minor' => $plan === null ? null : (int) $plan];
            }
            $total = array_sum(array_column($lines, 'requested_minor'));
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('CV-%s-%05d', $period, DocumentNumbers::next($this->store->tenantId, 'CASH', $period));
            DB::table('cash_vouchers')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'trip_id' => $trip->id, 'transport_order_id' => $trip->transport_order_id, 'driver_id' => $trip->driver_id,
                'status' => 'REQUESTED', 'purpose' => $d['purpose'] ?? null, 'requested_minor' => $total, 'requested_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                DB::table('cash_voucher_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'voucher_id' => $id, 'charge_code_id' => $line['charge_code_id'], 'kind' => $line['kind'],
                    'planned_minor' => $line['planned_minor'], 'requested_minor' => $line['requested_minor'], 'reason' => $line['reason'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->store->event('cash.requested', $id, ['number' => $number, 'trip' => $trip->number, 'requested_minor' => $total]);

            return $this->show($id);
        }, 3);
    }

    /** @param  array<string, int>  $approved  approved amount per line id; omitted lines keep what was asked */
    public function approve(string $id, array $approved, bool $canApproveLarge): array
    {
        return DB::transaction(function () use ($id, $approved, $canApproveLarge) {
            $this->lock();
            $voucher = $this->store->find('cash_vouchers', $id, true);
            abort_unless($voucher->status === 'REQUESTED', 409, 'Voucher tidak menunggu persetujuan.');
            abort_if($voucher->requested_by === $this->store->actorId, 403, 'Pemohon tidak boleh menyetujui permohonannya sendiri.');
            $total = 0;
            foreach ($this->store->query('cash_voucher_lines')->where('voucher_id', $id)->get() as $line) {
                $amount = array_key_exists($line->id, $approved) ? (int) $approved[$line->id] : (int) $line->requested_minor;
                if ($amount < 0 || $amount > $line->requested_minor) {
                    $this->fail('lines', 'Jumlah disetujui tidak boleh melebihi yang diminta.');
                }
                DB::table('cash_voucher_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['approved_minor' => $amount, 'updated_at' => now()]);
                $total += $amount;
            }
            abort_if($total <= 0, 422, 'Total yang disetujui harus lebih dari nol. Tolak voucher bila tidak disetujui.');
            abort_if($total > $this->limit() && ! $canApproveLarge, 403, 'Jumlah melebihi batas persetujuan Anda. Voucher ini perlu disetujui pejabat dengan izin persetujuan nominal besar.');
            DB::table('cash_vouchers')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'APPROVED', 'approved_minor' => $total, 'approved_by' => $this->store->actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->store->event('cash.approved', $id, ['number' => $voucher->number, 'approved_minor' => $total, 'requested_minor' => $voucher->requested_minor]);

            return $this->show($id);
        }, 3);
    }

    public function reject(string $id, string $reason): array
    {
        return $this->close($id, $reason, ['REQUESTED'], 'REJECTED', 'cash.rejected', true);
    }

    public function cancel(string $id, string $reason): array
    {
        return $this->close($id, $reason, ['REQUESTED', 'APPROVED'], 'CANCELLED', 'cash.cancelled', false);
    }

    private function close(string $id, string $reason, array $from, string $to, string $event, bool $notOwn): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason, $from, $to, $event, $notOwn) {
            $this->lock();
            $voucher = $this->store->find('cash_vouchers', $id, true);
            abort_unless(in_array($voucher->status, $from, true), 409, 'Status voucher tidak memungkinkan aksi ini.');
            abort_if($notOwn && $voucher->requested_by === $this->store->actorId, 403, 'Pemohon tidak boleh menolak permohonannya sendiri; batalkan saja.');
            DB::table('cash_vouchers')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => $to, 'status_reason' => $reason, 'updated_at' => now()]);
            $this->store->event($event, $id, ['number' => $voucher->number, 'reason' => $reason]);

            return $this->show($id);
        }, 3);
    }

    public function disburse(string $id, string $cashboxId): array
    {
        return DB::transaction(function () use ($id, $cashboxId) {
            $this->lock();
            $voucher = $this->store->find('cash_vouchers', $id, true);
            abort_unless($voucher->status === 'APPROVED', 409, 'Voucher belum disetujui atau sudah dicairkan.');
            abort_if($voucher->requested_by === $this->store->actorId, 403, 'Pemohon tidak boleh mencairkan permohonannya sendiri.');
            $box = $this->store->query('cashboxes')->where('id', $cashboxId)->lockForUpdate()->first() ?? $this->fail('cashbox_id', 'Kas tidak ditemukan.');
            abort_unless($box->active, 409, 'Kas tidak aktif.');
            if ($this->cashboxBalance($box->id) < $voucher->approved_minor) {
                $this->fail('cashbox_id', 'Saldo kas tidak cukup untuk pencairan ini.');
            }
            $this->entry('DISBURSE', -$voucher->approved_minor, $box->id, null, $id, "Pencairan {$voucher->number}");
            $this->entry('DISBURSE', $voucher->approved_minor, null, $voucher->driver_id, $id, "Pencairan {$voucher->number}");
            DB::table('cash_vouchers')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'DISBURSED', 'cashbox_id' => $box->id, 'disbursed_minor' => $voucher->approved_minor, 'disbursed_by' => $this->store->actorId, 'disbursed_at' => now(), 'updated_at' => now()]);
            $this->store->event('cash.disbursed', $id, ['number' => $voucher->number, 'cashbox' => $box->code, 'amount_minor' => $voucher->approved_minor]);

            return $this->show($id);
        }, 3);
    }

    /** @param  list<array{id: string, actual_minor: int, receipt_ref?: ?string}>  $actuals */
    public function settle(string $id, array $actuals, int $returned): array
    {
        $d = Validator::make(['lines' => $actuals, 'returned' => $returned], [
            'lines' => 'required|array|min:1', 'lines.*.id' => 'required|uuid', 'lines.*.actual_minor' => 'required|integer|min:0|max:100000000000', 'lines.*.receipt_ref' => 'nullable|string|max:120',
            'returned' => 'required|integer|min:0|max:100000000000',
        ], [], ['lines.*.actual_minor' => 'Realisasi', 'returned' => 'Uang dikembalikan'])->validate();

        return DB::transaction(function () use ($id, $d) {
            $this->lock();
            $voucher = $this->store->find('cash_vouchers', $id, true);
            abort_unless($voucher->status === 'DISBURSED', 409, 'Voucher belum dicairkan atau sudah direalisasi.');
            $lines = $this->store->query('cash_voucher_lines')->where('voucher_id', $id)->get()->keyBy('id');
            $given = collect($d['lines'])->keyBy('id');
            if ($given->count() !== $lines->count() || $given->keys()->diff($lines->keys())->isNotEmpty()) {
                $this->fail('lines', 'Realisasi harus mencakup semua baris voucher.');
            }
            $actual = 0;
            foreach ($lines as $line) {
                $g = $given[$line->id];
                if ((int) $g['actual_minor'] > 0 && mb_strlen(trim((string) ($g['receipt_ref'] ?? ''))) < 3) {
                    $this->fail('lines', 'Setiap pengeluaran wajib memiliki nomor/keterangan nota.');
                }
                $actual += (int) $g['actual_minor'];
            }
            $disbursed = (int) $voucher->disbursed_minor;
            $returned = (int) $d['returned'];
            $reimbursed = max(0, $actual - $disbursed);
            if ($returned > max(0, $disbursed - $actual)) {
                $this->fail('returned', 'Uang dikembalikan tidak boleh melebihi sisa uang jalan.');
            }
            if ($reimbursed > 0 && $this->cashboxBalance($voucher->cashbox_id) < $reimbursed) {
                $this->fail('returned', 'Saldo kas tidak cukup untuk mengganti kelebihan pengeluaran.');
            }

            foreach ($lines as $line) {
                $g = $given[$line->id];
                DB::table('cash_voucher_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['actual_minor' => (int) $g['actual_minor'], 'receipt_ref' => $g['receipt_ref'] ?? null, 'updated_at' => now()]);
                $excess = (int) $g['actual_minor'] - ($line->kind === 'PLAN' ? (int) ($line->planned_minor ?? 0) : 0);
                if ($excess > 0) {
                    DB::table('spm_charges')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $voucher->transport_order_id, 'side' => 'BUY', 'charge_code_id' => $line->charge_code_id,
                        'kind' => 'UNPLAN', 'source' => 'MANUAL', 'amount_minor' => $excess, 'status' => 'PROPOSED', 'cash_voucher_id' => $voucher->id,
                        'reason' => mb_substr("Uang jalan {$voucher->number}: ".($g['receipt_ref'] ?? '').($line->reason ? ' — '.$line->reason : ''), 0, 500),
                        'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($actual > 0) {
                $this->entry('EXPENSE', -$actual, null, $voucher->driver_id, $id, "Realisasi {$voucher->number}");
            }
            if ($returned > 0) {
                $this->entry('RETURN', -$returned, null, $voucher->driver_id, $id, "Pengembalian {$voucher->number}");
                $this->entry('RETURN', $returned, $voucher->cashbox_id, null, $id, "Pengembalian {$voucher->number}");
            }
            if ($reimbursed > 0) {
                $this->entry('REIMBURSE', -$reimbursed, $voucher->cashbox_id, null, $id, "Pengganti kelebihan {$voucher->number}");
                $this->entry('REIMBURSE', $reimbursed, null, $voucher->driver_id, $id, "Pengganti kelebihan {$voucher->number}");
            }
            DB::table('cash_vouchers')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'SETTLED', 'actual_minor' => $actual, 'returned_minor' => $returned, 'reimbursed_minor' => $reimbursed, 'settled_by' => $this->store->actorId, 'settled_at' => now(), 'updated_at' => now()]);
            $this->store->event('cash.settled', $id, ['number' => $voucher->number, 'disbursed_minor' => $disbursed, 'actual_minor' => $actual, 'returned_minor' => $returned, 'reimbursed_minor' => $reimbursed, 'driver_holds_minor' => max(0, $disbursed - $actual - $returned)]);

            return $this->show($id);
        }, 3);
    }
}
