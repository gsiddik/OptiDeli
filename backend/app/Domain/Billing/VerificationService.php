<?php

namespace App\Domain\Billing;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verification of the charges of an SPM after the cargo was discharged. A charge is PROPOSED when the tariff priced it or somebody
 * added it; a person other than its maker verifies it (it can then be billed or counts in the margin) or rejects it with a reason (it
 * counts nowhere; the fix is a new charge). A verified charge never changes again.
 */
final class VerificationService
{
    /** SPM statuses after which charges can be verified. */
    private const VERIFIABLE = ['DISCHARGED', 'DOC_VERIFIED', 'CLOSED'];

    public function __construct(private readonly TenantStore $store) {}

    /**
     * @param  list<string>|null  $chargeIds  the charges to decide; null = every PROPOSED charge of the side
     * @return array{decided: int, total_minor: int, status: string}
     */
    public function decide(string $spmId, string $side, string $decision, ?array $chargeIds, ?string $note): array
    {
        if (! in_array($side, ['SELL', 'BUY'], true) || ! in_array($decision, ['VERIFY', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan atau sisi tidak dikenal.']);
        }
        if ($decision === 'REJECT' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($spmId, $side, $decision, $chargeIds, $note) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->store->find('transport_orders', $spmId, true);
            abort_unless(in_array($spm->status, self::VERIFIABLE, true), 409, 'Biaya diverifikasi setelah SPM selesai bongkar.');
            $query = $this->store->query('spm_charges')->where('transport_order_id', $spmId)->where('side', $side)->where('status', 'PROPOSED')->lockForUpdate();
            if ($chargeIds !== null) {
                $query->whereIn('id', $chargeIds);
            }
            $charges = $query->get();
            abort_if($charges->isEmpty(), 422, 'Tidak ada biaya yang menunggu keputusan.');
            if ($chargeIds !== null && $charges->count() !== count(array_unique($chargeIds))) {
                abort(422, 'Sebagian biaya tidak ditemukan atau sudah diputuskan.');
            }
            abort_if($charges->contains(fn ($c) => $c->created_by === $this->store->actorId), 403, 'Anda tidak boleh memverifikasi biaya yang Anda buat sendiri.');

            $status = $decision === 'VERIFY' ? 'VERIFIED' : 'REJECTED';
            DB::table('spm_charges')->where('tenant_id', $this->store->tenantId)->whereIn('id', $charges->pluck('id'))
                ->update(['status' => $status, 'verified_by' => $this->store->actorId, 'verified_at' => now(), 'verify_note' => $note, 'updated_at' => now()]);
            $total = (int) $charges->sum('amount_minor');
            $this->store->event($decision === 'VERIFY' ? 'spm.charges_verified' : 'spm.charges_rejected', $spmId, ['number' => $spm->number, 'side' => $side, 'count' => $charges->count(), 'total_minor' => $total, 'note' => $note]);

            return ['decided' => $charges->count(), 'total_minor' => $total, 'status' => $status];
        }, 3);
    }
}
