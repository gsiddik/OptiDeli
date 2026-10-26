<?php

namespace App\Domain\Cutover;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Moving a customer (or one of its projects) from the legacy system (ARK / OP) to OptiDeli: a checklist, a parallel period in which
 * both systems are compared month by month against control totals taken from the legacy system, and a gate ("Gerbang C") that only
 * opens when everything is ready and someone other than the creator approves. After the cut-over the legacy side is marked frozen.
 *
 * A month of the reconciliation is the month of the SPM's planned pickup (its creation when none). It counts SPMs that left draft
 * and were not cancelled, and the verified selling and buying charges of those SPMs. Fixed-cost contracts are not part of it.
 */
final class CutoverService
{
    public const CHECKS = [
        'MASTER_LOADED' => 'Master data (pelanggan, lokasi, armada, kode biaya) sudah termuat dan diperiksa',
        'TARIFF_APPROVED' => 'Tarif jual dan beli sudah disetujui',
        'USERS_TRAINED' => 'Pengguna sudah dilatih dan akun sudah dibuat',
        'PARALLEL_RECONCILED' => 'Periode paralel sudah direkonsiliasi dan selisih dijelaskan',
        'LEGACY_OPEN_CLOSED' => 'Dokumen terbuka di sistem legacy sudah ditutup atau disepakati penyelesaiannya',
        'LEGACY_READY_TO_FREEZE' => 'Sistem legacy siap dibekukan (hanya baca)',
    ];

    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public function list(): array
    {
        $rows = $this->store->query('cutover_projects')->orderBy('code')->get();
        $names = $this->store->query('customers')->pluck('name', 'id');
        $projects = $this->store->query('customer_projects')->pluck('name', 'id');
        $checks = $this->store->query('cutover_checks')->where('done', true)->selectRaw('cutover_id, count(*) as n')->groupBy('cutover_id')->pluck('n', 'cutover_id');
        foreach ($rows as $row) {
            $row->customer_label = $names[$row->customer_id] ?? null;
            $row->project_label = $row->project_id ? ($projects[$row->project_id] ?? null) : null;
            $row->checks_done = (int) ($checks[$row->id] ?? 0);
            $row->checks_total = count(self::CHECKS);
        }

        return $rows->all();
    }

    public function show(string $id): array
    {
        $cutover = $this->store->find('cutover_projects', $id);
        $cutover->customer_label = $this->store->query('customers')->where('id', $cutover->customer_id)->value('name');
        $cutover->project_label = $cutover->project_id ? $this->store->query('customer_projects')->where('id', $cutover->project_id)->value('name') : null;
        $checks = $this->store->query('cutover_checks')->where('cutover_id', $id)->get()->keyBy('code');
        $users = DB::table('users')->whereIn('id', $checks->pluck('done_by')->filter())->pluck('name', 'id');
        $list = [];
        foreach (self::CHECKS as $code => $label) {
            $c = $checks[$code] ?? null;
            $list[] = ['code' => $code, 'label' => $label, 'done' => (bool) ($c->done ?? false), 'done_by_name' => $c && $c->done_by ? ($users[$c->done_by] ?? null) : null, 'done_at' => $c->done_at ?? null, 'note' => $c->note ?? null];
        }
        $reconciliation = $this->reconcile($cutover);

        return ['cutover' => $cutover, 'checks' => $list, 'reconciliation' => $reconciliation, 'gate' => $this->gate($cutover, $list, $reconciliation)];
    }

    public function create(array $input): array
    {
        $d = Validator::make($input, [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => 'required|string|max:150', 'customer_id' => 'required|uuid', 'project_id' => 'nullable|uuid',
            'legacy_system' => ['required', Rule::in(['ARK', 'OP', 'OTHER'])], 'parallel_from' => 'nullable|date', 'planned_cutover_on' => 'nullable|date|after_or_equal:parallel_from',
            'tolerance_minor' => 'nullable|integer|min:0|max:1000000000', 'notes' => 'nullable|string|max:2000',
        ])->validate();
        $d['code'] = strtoupper($d['code']);
        $customer = $this->store->query('customers')->where('id', $d['customer_id'])->first();
        if (! $customer || ! $customer->active) {
            $this->fail('customer_id', 'Pelanggan tidak ditemukan atau tidak aktif.');
        }
        if (! empty($d['project_id']) && ! $this->store->query('customer_projects')->where('id', $d['project_id'])->where('customer_id', $d['customer_id'])->exists()) {
            $this->fail('project_id', 'Proyek bukan milik pelanggan ini.');
        }
        if ($this->store->query('cutover_projects')->where('code', $d['code'])->exists()) {
            $this->fail('code', 'Kode cut-over sudah digunakan.');
        }
        if ($this->store->query('cutover_projects')->where('customer_id', $d['customer_id'])->where('project_id', $d['project_id'] ?? null)->exists()) {
            $this->fail('customer_id', 'Pelanggan/proyek ini sudah memiliki pelacak cut-over.');
        }

        return DB::transaction(function () use ($d, $customer) {
            $id = (string) Str::uuid();
            DB::table('cutover_projects')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'status' => 'PLANNING', 'tolerance_minor' => $d['tolerance_minor'] ?? 0, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_keys(self::CHECKS) as $code) {
                DB::table('cutover_checks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'cutover_id' => $id, 'code' => $code, 'done' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->store->event('cutover.created', $id, ['code' => $d['code'], 'customer' => $customer->code, 'legacy_system' => $d['legacy_system']]);

            return $this->show($id);
        });
    }

    public function update(string $id, array $input): array
    {
        $cutover = $this->store->find('cutover_projects', $id);
        $d = Validator::make($input, [
            'name' => 'required|string|max:150', 'parallel_from' => 'nullable|date', 'planned_cutover_on' => 'nullable|date|after_or_equal:parallel_from',
            'tolerance_minor' => 'nullable|integer|min:0|max:1000000000', 'notes' => 'nullable|string|max:2000',
        ])->validate();
        $d['tolerance_minor'] ??= 0;
        DB::transaction(function () use ($cutover, $d) {
            DB::table('cutover_projects')->where('tenant_id', $this->store->tenantId)->where('id', $cutover->id)->update($d + ['updated_at' => now()]);
            $this->store->event('cutover.updated', $cutover->id, ['code' => $cutover->code]);
        });

        return $this->show($id);
    }

    public function setCheck(string $id, string $code, array $input): array
    {
        $d = Validator::make($input, ['done' => 'required|boolean', 'note' => 'nullable|string|max:300'])->validate();
        abort_unless(array_key_exists($code, self::CHECKS), 404);

        return DB::transaction(function () use ($id, $code, $d) {
            $cutover = $this->store->find('cutover_projects', $id, true);
            $this->assertNotFrozen($cutover);
            DB::table('cutover_checks')->where('cutover_id', $id)->where('code', $code)->update([
                'done' => $d['done'], 'done_by' => $d['done'] ? $this->store->actorId : null, 'done_at' => $d['done'] ? now() : null, 'note' => $d['note'] ?? null, 'updated_at' => now(),
            ]);
            $this->store->event($d['done'] ? 'cutover.check_done' : 'cutover.check_reopened', $id, ['check' => $code]);

            return $this->show($id);
        });
    }

    /** One month of legacy control totals; entering it again replaces the figures and withdraws an earlier acceptance. */
    public function saveTotals(string $id, array $input): array
    {
        $d = Validator::make($input, [
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'spm_count' => 'required|integer|min:0|max:10000000',
            'sell_minor' => 'required|integer|min:0', 'buy_minor' => 'required|integer|min:0', 'source_note' => 'nullable|string|max:200',
        ])->validate();

        return DB::transaction(function () use ($id, $d) {
            $cutover = $this->store->find('cutover_projects', $id, true);
            $this->assertNotFrozen($cutover);
            $row = ['spm_count' => $d['spm_count'], 'sell_minor' => $d['sell_minor'], 'buy_minor' => $d['buy_minor'], 'source_note' => $d['source_note'] ?? null, 'entered_by' => $this->store->actorId, 'accepted_by' => null, 'accepted_at' => null, 'accept_note' => null, 'updated_at' => now()];
            $exists = DB::table('legacy_control_totals')->where('cutover_id', $id)->where('period', $d['period'])->exists();
            if ($exists) {
                DB::table('legacy_control_totals')->where('cutover_id', $id)->where('period', $d['period'])->update($row);
            } else {
                DB::table('legacy_control_totals')->insert($row + ['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'cutover_id' => $id, 'period' => $d['period'], 'created_at' => now()]);
            }
            $this->store->event('cutover.totals_saved', $id, ['period' => $d['period'], 'replaced' => $exists]);

            return $this->show($id);
        });
    }

    public function removeTotals(string $id, string $period): array
    {
        return DB::transaction(function () use ($id, $period) {
            $cutover = $this->store->find('cutover_projects', $id, true);
            $this->assertNotFrozen($cutover);
            abort_unless(DB::table('legacy_control_totals')->where('cutover_id', $id)->where('period', $period)->delete() === 1, 404);
            $this->store->event('cutover.totals_removed', $id, ['period' => $period]);

            return $this->show($id);
        });
    }

    /** Someone explains a difference that will stay (a known legacy error, a cancelled SPM, a timing issue). */
    public function accept(string $id, string $period, array $input): array
    {
        $d = Validator::make($input, ['note' => 'required|string|min:5|max:300'])->validate();

        return DB::transaction(function () use ($id, $period, $d) {
            $cutover = $this->store->find('cutover_projects', $id, true);
            $this->assertNotFrozen($cutover);
            $row = DB::table('legacy_control_totals')->where('cutover_id', $id)->where('period', $period)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->entered_by === $this->store->actorId) {
                abort(403, 'Selisih yang Anda masukkan sendiri harus diterima oleh orang lain.');
            }
            DB::table('legacy_control_totals')->where('id', $row->id)->update(['accepted_by' => $this->store->actorId, 'accepted_at' => now(), 'accept_note' => $d['note'], 'updated_at' => now()]);
            $this->store->event('cutover.difference_accepted', $id, ['period' => $period, 'note' => $d['note']]);

            return $this->show($id);
        });
    }

    /** PLANNING -> PARALLEL, PARALLEL -> CUT_OVER (the gate), CUT_OVER -> PARALLEL (rollback, with a reason). */
    public function move(string $id, string $to, ?string $reason, bool $mayApprove): array
    {
        return DB::transaction(function () use ($id, $to, $reason, $mayApprove) {
            $cutover = $this->store->find('cutover_projects', $id, true);
            $from = $cutover->status;
            $allowed = ['PLANNING' => ['PARALLEL'], 'PARALLEL' => ['CUT_OVER'], 'CUT_OVER' => ['PARALLEL']];
            abort_unless(in_array($to, $allowed[$from] ?? [], true), 409, "Status {$from} tidak dapat langsung menjadi {$to}.");
            $update = ['status' => $to, 'updated_at' => now()];
            if ($to === 'PARALLEL' && $from === 'PLANNING') {
                $update['parallel_from'] = $cutover->parallel_from ?? now()->toDateString();
            }
            if ($to === 'CUT_OVER') {
                abort_unless($mayApprove, 403, 'Hanya yang berwenang menyetujui cut-over.');
                abort_if($cutover->created_by === $this->store->actorId, 403, 'Cut-over harus disetujui oleh orang lain selain pembuatnya.');
                $shown = $this->show($id);
                if (! $shown['gate']['open']) {
                    $this->fail('status', 'Gerbang C belum terbuka: '.implode(' ', $shown['gate']['blockers']));
                }
                $update += ['cut_over_at' => now(), 'legacy_frozen_at' => now(), 'approved_by' => $this->store->actorId];
            }
            if ($from === 'CUT_OVER') {
                abort_unless($mayApprove, 403, 'Hanya yang berwenang membatalkan cut-over.');
                if (mb_strlen(trim((string) $reason)) < 5) {
                    $this->fail('reason', 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
                }
                $update += ['cut_over_at' => null, 'legacy_frozen_at' => null, 'approved_by' => null];
            }
            DB::table('cutover_projects')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($update);
            $this->store->event('cutover.moved', $id, ['code' => $cutover->code, 'from' => $from, 'to' => $to, 'reason' => $reason]);

            return $this->show($id);
        });
    }

    private function assertNotFrozen(object $cutover): void
    {
        abort_if($cutover->status === 'CUT_OVER', 409, 'Cut-over sudah berjalan; batalkan dulu untuk mengubah data rekonsiliasi.');
    }

    /** @return list<array<string, mixed>> one entry per month that has legacy totals or OptiDeli activity */
    private function reconcile(object $cutover): array
    {
        $totals = $this->store->query('legacy_control_totals')->where('cutover_id', $cutover->id)->get()->keyBy('period');
        $spm = DB::table('transport_orders as t')->where('t.tenant_id', $this->store->tenantId)->where('t.customer_id', $cutover->customer_id)
            ->when($cutover->project_id, fn ($q) => $q->where('t.project_id', $cutover->project_id))
            ->whereNotIn('t.status', ['DRAFT', 'CANCELLED'])
            ->selectRaw("to_char(coalesce(t.planned_pickup_at, t.created_at) AT TIME ZONE 'UTC', 'YYYY-MM') as period, count(*) as spm_count,
                coalesce(sum((select coalesce(sum(c.amount_minor), 0) from spm_charges c where c.transport_order_id = t.id and c.side = 'SELL' and c.status = 'VERIFIED')), 0) as sell_minor,
                coalesce(sum((select coalesce(sum(c.amount_minor), 0) from spm_charges c where c.transport_order_id = t.id and c.side = 'BUY' and c.status = 'VERIFIED')), 0) as buy_minor")
            ->groupBy('period')->get()->keyBy('period');
        $from = $cutover->parallel_from ? substr($cutover->parallel_from, 0, 7) : null;
        $periods = $totals->keys()->merge($spm->keys()->filter(fn ($p) => ! $from || $p >= $from))->unique()->sort()->values();
        $now = now()->format('Y-m');
        $out = [];
        foreach ($periods as $period) {
            $legacy = $totals[$period] ?? null;
            $deli = $spm[$period] ?? null;
            $row = [
                'period' => $period,
                'deli' => ['spm_count' => (int) ($deli->spm_count ?? 0), 'sell_minor' => (int) ($deli->sell_minor ?? 0), 'buy_minor' => (int) ($deli->buy_minor ?? 0)],
                'legacy' => $legacy ? ['spm_count' => (int) $legacy->spm_count, 'sell_minor' => (int) $legacy->sell_minor, 'buy_minor' => (int) $legacy->buy_minor, 'source_note' => $legacy->source_note] : null,
                'diff' => null, 'accept_note' => $legacy->accept_note ?? null, 'accepted' => (bool) ($legacy->accepted_at ?? false),
            ];
            if (! $legacy) {
                $row['status'] = $period < $now ? 'NO_LEGACY' : 'OPEN';
            } else {
                $row['diff'] = ['spm_count' => $row['deli']['spm_count'] - $row['legacy']['spm_count'], 'sell_minor' => $row['deli']['sell_minor'] - $row['legacy']['sell_minor'], 'buy_minor' => $row['deli']['buy_minor'] - $row['legacy']['buy_minor']];
                $within = $row['diff']['spm_count'] === 0 && abs($row['diff']['sell_minor']) <= $cutover->tolerance_minor && abs($row['diff']['buy_minor']) <= $cutover->tolerance_minor;
                $row['status'] = $within ? 'MATCHED' : ($row['accepted'] ? 'ACCEPTED' : 'DIFFERENT');
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return array{open: bool, blockers: list<string>} */
    private function gate(object $cutover, array $checks, array $reconciliation): array
    {
        $blockers = [];
        if ($cutover->status === 'PLANNING') {
            $blockers[] = 'Mulai masa paralel lebih dulu.';
        }
        $open = array_filter($checks, fn ($c) => ! $c['done']);
        if ($open) {
            $blockers[] = 'Daftar periksa belum lengkap ('.count($open).' butir).';
        }
        $legacy = array_filter($reconciliation, fn ($r) => $r['legacy'] !== null);
        if (! $legacy) {
            $blockers[] = 'Belum ada total kontrol legacy untuk direkonsiliasi.';
        }
        foreach ($reconciliation as $r) {
            if ($r['status'] === 'DIFFERENT') {
                $blockers[] = "Selisih bulan {$r['period']} belum dijelaskan.";
            }
            if ($r['status'] === 'NO_LEGACY') {
                $blockers[] = "Bulan {$r['period']} belum punya total kontrol legacy.";
            }
        }

        return ['open' => $blockers === [] && $cutover->status === 'PARALLEL', 'blockers' => $blockers];
    }
}
