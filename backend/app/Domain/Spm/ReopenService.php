<?php

namespace App\Domain\Spm;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The special queue for re-opening an SPM. Nobody re-opens an SPM directly: someone who manages SPMs files a request with a reason
 * for an SPM that was already discharged, verified or closed; a different person who may re-open (spm.reopen) approves it, and only
 * then does the SPM become REOPENED, or rejects it with a note. One request can be pending per SPM.
 */
final class ReopenService
{
    public function __construct(private readonly TenantStore $store, private readonly SpmService $spm) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function list(?string $status, int $page, int $perPage = 50): array
    {
        $query = $this->store->query('spm_reopen_requests');
        if (in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $rows = $query->orderByRaw("case when status = 'PENDING' then 0 else 1 end")->orderByDesc('created_at')->forPage($page, $perPage)->get();
        $spms = $this->store->query('transport_orders')->whereIn('id', $rows->pluck('transport_order_id'))->get(['id', 'number', 'status'])->keyBy('id');
        $users = DB::table('users')->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by')->filter()))->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->spm_number = $spms[$row->transport_order_id]->number ?? null;
            $row->spm_status = $spms[$row->transport_order_id]->status ?? null;
            $row->requested_by_name = $users[$row->requested_by] ?? null;
            $row->decided_by_name = $row->decided_by ? ($users[$row->decided_by] ?? null) : null;
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public function pendingFor(string $spmId): ?object
    {
        return $this->store->query('spm_reopen_requests')->where('transport_order_id', $spmId)->where('status', 'PENDING')->first();
    }

    public function request(string $spmId, string $reason): object
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($spmId, $reason) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->store->find('transport_orders', $spmId, true);
            abort_unless(in_array($spm->status, ['DISCHARGED', 'DOC_VERIFIED', 'CLOSED'], true), 409, 'SPM hanya dapat dibuka kembali setelah selesai bongkar.');
            abort_if($this->pendingFor($spmId) !== null, 409, 'Sudah ada permintaan buka kembali yang menunggu keputusan untuk SPM ini.');
            $id = (string) Str::uuid();
            DB::table('spm_reopen_requests')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $spmId, 'from_status' => $spm->status, 'reason' => $reason, 'status' => 'PENDING', 'requested_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('spm.reopen_requested', $id, ['number' => $spm->number, 'from' => $spm->status, 'reason' => $reason]);

            return $this->store->find('spm_reopen_requests', $id);
        }, 3);
    }

    public function decide(string $id, bool $approve, ?string $note): object
    {
        if (! $approve && mb_strlen(trim((string) $note)) < 5) {
            $this->fail('note', 'Catatan penolakan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $approve, $note) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $request = $this->store->find('spm_reopen_requests', $id, true);
            abort_unless($request->status === 'PENDING', 409, 'Permintaan ini sudah diputuskan.');
            abort_if($request->requested_by === $this->store->actorId, 403, 'Pemohon tidak boleh memutuskan permintaannya sendiri.');
            $spm = $this->store->find('transport_orders', $request->transport_order_id, true);
            if ($approve) {
                abort_unless($spm->status === $request->from_status, 409, 'Status SPM sudah berubah sejak permintaan diajukan. Tolak permintaan ini dan ajukan ulang bila perlu.');
                $this->spm->transition($spm->id, 'REOPENED', $request->reason, true);
            }
            DB::table('spm_reopen_requests')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'decided_by' => $this->store->actorId, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            $this->store->event($approve ? 'spm.reopen_approved' : 'spm.reopen_rejected', $id, ['number' => $spm->number, 'note' => $note]);

            return $this->store->find('spm_reopen_requests', $id);
        }, 3);
    }

    public function cancel(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $request = $this->store->find('spm_reopen_requests', $id, true);
            abort_unless($request->status === 'PENDING', 409, 'Permintaan ini sudah diputuskan.');
            abort_unless($request->requested_by === $this->store->actorId, 403, 'Hanya pemohon yang dapat menarik permintaannya.');
            DB::table('spm_reopen_requests')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->store->event('spm.reopen_cancelled', $id, []);

            return $this->store->find('spm_reopen_requests', $id);
        });
    }
}
