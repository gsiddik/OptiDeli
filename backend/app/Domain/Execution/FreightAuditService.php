<?php

namespace App\Domain\Execution;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FreightAuditService
{
    public function refreshTrip(TenantStore $s, string $tripId): void
    {
        foreach ($s->query('freight_invoices')->where('trip_id', $tripId)->where('status', '<>', 'VOID')->get() as $invoice) {
            $s->query('freight_invoices')->where('id', $invoice->id)->update(['version' => $invoice->version + 1, 'status' => 'ON_HOLD', 'updated_at' => now()]);
            if ($invoice->status === 'READY_FOR_PAYMENT') {
                $s->event('freight.settlement_held', $invoice->id, ['reason' => 'NEW_EVIDENCE', 'version' => $invoice->version + 1]);
            }
            $this->assess($s, $invoice->id);
        }
    }

    public function assess(TenantStore $s, string $id): object
    {
        $i = $s->find('freight_invoices', $id, true);
        if ($i->status === 'VOID') {
            return $i;
        }
        $trip = $s->find('trips', $i->trip_id);
        $plan = $s->find('dispatch_plans', $trip->plan_id);
        $order = $s->find('orders', $trip->order_id);
        $extra = $i->accessorial_minor - $plan->vendor_accessorial_minor;
        $verified = (int) $s->query('accessorial_claims')->where('trip_id', $trip->id)->where('status', 'VERIFIED')->sum('amount_minor');
        $pending = $s->query('accessorial_claims')->where('trip_id', $trip->id)->where('status', 'SUBMITTED')->exists();
        $pod = $s->query('proofs_of_delivery')->where('trip_id', $trip->id)->where('status', 'VERIFIED')->where('outcome', 'DELIVERED')->first();
        $tier = FreightRules::tier($order->sell_minor, $i->total_minor, $extra, $i->rounding_minor);
        $reason = 'APPROVAL_REQUIRED';
        if ($i->base_minor !== $plan->vendor_base_minor) {
            $reason = 'BASE_RATE_MISMATCH';
            if (! $s->query('freight_disputes')->where('invoice_id', $id)->where('system_key', 'base:'.$i->version)->exists()) {
                DB::table('freight_disputes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $s->tenantId, 'invoice_id' => $id, 'message' => 'Tarif dasar tidak sesuai kontrak. Vendor wajib mengunggah invoice koreksi sebelum settlement.', 'system_key' => 'base:'.$i->version, 'status' => 'OPEN', 'created_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now()]);
                $s->event('freight.invoice_correction_requested', $id);
            }
        } elseif (! $pod || ! PrivateEvidence::valid($pod) || ! PrivateEvidence::valid($i) || $trip->status !== 'COMPLETED') {
            $reason = 'DELIVERY_EVIDENCE_REQUIRED';
        } elseif ($pending || $extra !== $verified) {
            $reason = 'UNVERIFIED_ACCESSORIAL';

        } else {
            if ($tier === 'CFO' && ! $s->query('freight_disputes')->where('invoice_id', $id)->where('system_key', 'cfo:'.$i->version)->exists()) {
                DB::table('freight_disputes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $s->tenantId, 'invoice_id' => $id, 'message' => 'Eskalasi CFO: selisih besar atau margin di bawah 5%. Tinjau bukti sebelum persetujuan.', 'system_key' => 'cfo:'.$i->version, 'status' => 'OPEN', 'created_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now()]);
                $s->event('freight.dispute_raised', $id, ['version' => $i->version]);
            }
            if ($s->query('freight_disputes')->where('invoice_id', $id)->where('status', '<>', 'RESOLVED')->exists()) {
                $reason = 'DISPUTE_OPEN';
            } else {
                $approvals = $s->query('invoice_approvals')->where('invoice_id', $id)->where('version', $i->version)->pluck('authority')->all();
                $ready = $tier === 'EXACT' || ($tier === 'FINANCE' && in_array('FINANCE', $approvals, true)) || ($tier === 'DUAL' && in_array('FINANCE_SUPERVISOR', $approvals, true) && in_array('LOGISTICS_MANAGER', $approvals, true)) || ($tier === 'CFO' && in_array('CFO', $approvals, true));
                if ($ready) {
                    $reason = 'MATCHED_AND_APPROVED';
                }
            }
        }
        $status = $reason === 'MATCHED_AND_APPROVED' ? 'READY_FOR_PAYMENT' : 'ON_HOLD';
        $s->query('freight_invoices')->where('id', $id)->update(['status' => $status, 'approval_tier' => $tier, 'hold_reason' => $reason, 'updated_at' => now()]);
        if ($status !== $i->status) {
            $s->event($status === 'READY_FOR_PAYMENT' ? 'freight.settlement_ready' : 'freight.settlement_held', $id, ['reason' => $reason, 'version' => $i->version]);
        }

        return $s->find('freight_invoices', $id);
    }

    public function approve(TenantStore $s, string $id, string $role, string $note, int $version): object
    {
        return DB::transaction(function () use ($s, $id, $role, $note, $version) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $i = $this->assess($s, $id);
            abort_unless($i->version === $version, 409, 'Versi invoice berubah; muat ulang bukti dan tinjau kembali.');
            abort_unless($i->status === 'ON_HOLD' && $i->hold_reason === 'APPROVAL_REQUIRED', 409, 'Bukti/dispute/koreksi tagihan belum selesai atau tagihan sudah disetujui.');
            abort_if($i->submitted_by === $s->actorId, 403, 'Penginput tagihan tidak dapat menyetujui tagihannya sendiri.');
            $authority = match (true) {
                $i->approval_tier === 'FINANCE' && in_array($role, ['finance', 'finance_supervisor'], true) => 'FINANCE',$i->approval_tier === 'DUAL' && $role === 'finance_supervisor' => 'FINANCE_SUPERVISOR',$i->approval_tier === 'DUAL' && in_array($role, ['supervisor', 'head_of_logistics'], true) => 'LOGISTICS_MANAGER',$i->approval_tier === 'CFO' && $role === 'cfo' => 'CFO',default => abort(403, 'Otoritas tidak sesuai tingkat eskalasi tagihan.')
            };
            abort_if($s->query('invoice_approvals')->where('invoice_id', $id)->where('version', $i->version)->where(fn ($q) => $q->where('actor_id', $s->actorId)->orWhere('authority', $authority))->exists(), 409, 'Approver atau otoritas ini sudah menyetujui versi invoice tersebut.');
            DB::table('invoice_approvals')->insert(['tenant_id' => $s->tenantId, 'invoice_id' => $id, 'version' => $i->version, 'actor_id' => $s->actorId, 'authority' => $authority, 'note' => $note, 'created_at' => now()]);
            $s->event('freight.approved', $id, ['authority' => $authority, 'version' => $i->version, 'note' => $note]);

            return $this->assess($s, $id);
        }, 3);
    }
}
