<?php

namespace App\Http\Controllers\Api;

use App\Domain\Execution\FreightAuditService;
use App\Domain\Execution\Permissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FreightController extends Controller
{
    private function ownTrip(Request $r, object $trip): void
    {
        if ($r->attributes->get('role') === 'vendor') {
            abort_unless($trip->vendor_id === $r->attributes->get('vendor_id'), 404);
        }
    }

    public function index(Request $r)
    {
        $filters = $r->validate(['status' => ['nullable', Rule::in(['ON_HOLD', 'READY_FOR_PAYMENT', 'VOID'])]]);

        return response()->json(OrderController::storeFor($r)->query('freight_invoices')->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('created_at')->paginate(25)->through(function ($i) {
            unset($i->path);

            return $i;
        }));
    }

    public function show(Request $r, string $id)
    {
        $s = OrderController::storeFor($r);
        $i = $s->find('freight_invoices', $id);
        $trip = $s->find('trips', $i->trip_id);
        $this->ownTrip($r, $trip);
        unset($i->path);
        $i->trip = $trip;
        $i->plan = $s->find('dispatch_plans', $trip->plan_id);
        $i->claims = $s->query('accessorial_claims')->where('trip_id', $trip->id)->get();
        $i->disputes = $s->query('freight_disputes')->where('invoice_id', $id)->get();
        $i->approvals = $s->query('invoice_approvals')->where('invoice_id', $id)->where('version', $i->version)->get();

        return response()->json(['data' => $i]);
    }

    public function create(Request $r, ?string $correction = null)
    {
        if ($r->attributes->get('role') !== 'vendor') {
            Permissions::finance($r);
        }
        $d = $r->validate(['trip_id' => 'required|uuid', 'number' => 'required|string|max:100', 'base_minor' => 'required|integer|min:0|max:1000000000000', 'accessorial_minor' => 'required|integer|min:0|max:1000000000000', 'rounding_minor' => 'required|integer|min:-1000000000000|max:1000000000000', 'document_reference' => 'required|string|max:200', 'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240']);
        $f = $r->file('file');
        unset($d['file']);
        $d['sha256'] = hash_file('sha256', $f->getRealPath());
        $s = OrderController::storeFor($r);
        $id = (string) Str::uuid();
        $path = $s->tenantId.'/invoices/'.$id;
        try {
            $invoice = $s->idempotent('invoice.create', OrderController::key($r), [...$d, 'correction' => $correction], function () use ($s, $r, $d, $f, $id, $path, $correction) {
                $trip = $s->find('trips', $d['trip_id'], true);
                $this->ownTrip($r, $trip);
                abort_unless($trip->vendor_id && $trip->contract_reference, 422, 'Vendor dan referensi kontrak trip wajib ditetapkan sebelum invoice.');
                $plan = $s->find('dispatch_plans', $trip->plan_id);
                abort_if($trip->status === 'CANCELLED', 422);
                if ($correction) {
                    $old = $s->find('freight_invoices', $correction, true);
                    $this->ownTrip($r, $s->find('trips', $old->trip_id));
                    abort_unless($old->trip_id === $trip->id && $old->status === 'ON_HOLD' && in_array($old->hold_reason, ['BASE_RATE_MISMATCH', 'UNVERIFIED_ACCESSORIAL'], true), 409, 'Invoice tidak dalam kondisi yang mengizinkan koreksi biaya.');
                    abort_unless($d['base_minor'] == $plan->vendor_base_minor, 422, 'Tarif dasar koreksi harus persis sesuai kontrak.');
                    abort_if($s->query('freight_disputes')->where('invoice_id', $old->id)->where('status', '<>', 'RESOLVED')->whereNull('system_key')->exists(), 409, 'Dispute manual harus ditinjau Finance dahulu.');
                    $s->query('freight_invoices')->where('id', $old->id)->update(['status' => 'VOID', 'updated_at' => now()]);
                    $s->query('freight_disputes')->where('invoice_id', $old->id)->where('system_key', 'like', 'base:%')->update(['status' => 'RESOLVED', 'resolved_by' => $s->actorId, 'resolution' => 'Invoice vendor dikoreksi; pengganti '.$id, 'updated_at' => now()]);
                }
                $total = $d['base_minor'] + $d['accessorial_minor'] + $d['rounding_minor'];
                abort_unless($total >= 0, 422, 'Total invoice tidak boleh negatif.');
                abort_unless(Storage::disk('local')->put($path, file_get_contents($f->getRealPath())), 500, 'Penyimpanan invoice gagal.');
                DB::table('freight_invoices')->insert([...$d, 'id' => $id, 'tenant_id' => $s->tenantId, 'vendor_id' => $trip->vendor_id, 'total_minor' => $total, 'path' => $path, 'mime' => $f->getMimeType(), 'status' => 'ON_HOLD', 'hold_reason' => 'PENDING_REVIEW', 'approval_tier' => 'PENDING', 'submitted_by' => $s->actorId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $s->event('freight.invoice_submitted', $id, ['trip_id' => $trip->id, 'replaces' => $correction, 'sha256' => $d['sha256']]);

                return app(FreightAuditService::class)->assess($s, $id);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        unset($invoice->path);

        return response()->json(['data' => $invoice], 201);
    }

    public function approve(Request $r, string $id, FreightAuditService $service)
    {
        $d = $r->validate(['note' => 'required|string|min:10|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $service->approve(OrderController::storeFor($r), $id, $r->attributes->get('role'), $d['note'], (int) $d['version'])]);
    }

    public function claim(Request $r)
    {
        if ($r->attributes->get('role') !== 'vendor') {
            OrderController::writeAccess($r);
        }$d = $r->validate(['trip_id' => 'required|uuid', 'category' => ['required', Rule::in(['DETENTION', 'DEMURRAGE', 'LOADING', 'UNLOADING', 'OVERNIGHT'])], 'amount_minor' => 'required|integer|min:1|max:1000000000000', 'reason' => 'required|string|min:10|max:2000', 'appointment_id' => 'nullable|uuid', 'evidence_reference' => 'required|string|min:5|max:300']);
        $s = OrderController::storeFor($r);
        $claim = $s->idempotent('claim.create', OrderController::key($r), $d, function () use ($s, $r, $d) {
            $trip = $s->find('trips', $d['trip_id'], true);
            $this->ownTrip($r, $trip);
            abort_if($trip->status === 'CANCELLED', 422);
            if (! empty($d['appointment_id'])) {
                $a = $s->find('dock_appointments', $d['appointment_id']);
                abort_unless($a->trip_id === $trip->id, 422, 'Bukti yard harus dari trip yang sama.');
            }$id = (string) Str::uuid();
            DB::table('accessorial_claims')->insert([...$d, 'id' => $id, 'tenant_id' => $s->tenantId, 'status' => 'SUBMITTED', 'submitted_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $s->event('freight.accessorial_claimed', $id, ['trip_id' => $trip->id, 'amount_minor' => $d['amount_minor']]);
            app(FreightAuditService::class)->refreshTrip($s, $trip->id);

            return $s->find('accessorial_claims', $id);
        });

        return response()->json(['data' => $claim], 201);
    }

    public function verifyClaim(Request $r, string $id)
    {
        Permissions::finance($r);
        $d = $r->validate(['decision' => ['required', Rule::in(['verify', 'reject'])], 'note' => 'required|string|min:10|max:2000']);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $id, $d) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $claim = $s->find('accessorial_claims', $id, true);
            abort_unless($claim->status === 'SUBMITTED', 409);
            abort_if($claim->submitted_by === $s->actorId, 403, 'Pengaju tidak dapat memverifikasi klaim sendiri.');
            $s->query('accessorial_claims')->where('id', $id)->update(['status' => $d['decision'] === 'verify' ? 'VERIFIED' : 'REJECTED', 'verified_by' => $s->actorId, 'verification_note' => $d['note'], 'updated_at' => now()]);
            $s->event('freight.claim_'.$d['decision'], $id, ['note' => $d['note']]);
            app(FreightAuditService::class)->refreshTrip($s, $claim->trip_id);
        }, 3);

        return response()->json(['data' => ['status' => 'recorded']]);
    }

    public function dispute(Request $r, string $id)
    {
        Permissions::finance($r);
        $d = $r->validate(['message' => 'required|string|min:20|max:4000']);
        $s = OrderController::storeFor($r);
        $disputeId = (string) Str::uuid();
        DB::transaction(function () use ($s, $id, $d, $disputeId) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $i = $s->find('freight_invoices', $id, true);
            abort_if($i->status === 'VOID', 409);
            DB::table('freight_disputes')->insert([...$d, 'id' => $disputeId, 'tenant_id' => $s->tenantId, 'invoice_id' => $id, 'status' => 'OPEN', 'created_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $s->event('freight.dispute_published', $disputeId, ['invoice_id' => $id]);
            app(FreightAuditService::class)->assess($s, $id);
        }, 3);

        return response()->json(['data' => $s->find('freight_disputes', $disputeId)], 201);
    }

    public function disputeAction(Request $r, string $id)
    {
        $d = $r->validate(['action' => ['required', Rule::in(['respond', 'resolve'])], 'note' => 'required|string|min:10|max:4000']);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $r, $id, $d) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $dispute = $s->find('freight_disputes', $id, true);
            $i = $s->find('freight_invoices', $dispute->invoice_id, true);
            abort_if($dispute->status === 'RESOLVED', 409);
            $trip = $s->find('trips', $i->trip_id);
            $this->ownTrip($r, $trip);
            if ($d['action'] === 'respond') {
                abort_unless($r->attributes->get('role') === 'vendor', 403);
                $s->query('freight_disputes')->where('id', $id)->update(['status' => 'RESPONDED', 'vendor_response' => $d['note'], 'updated_at' => now()]);
            } else {
                Permissions::finance($r);
                abort_if(str_starts_with($dispute->system_key ?? '', 'base:'), 409, 'Base rate harus dikoreksi melalui upload invoice revisi.');
                if ($i->approval_tier === 'CFO') {
                    abort_unless($r->attributes->get('role') === 'cfo', 403, 'Resolusi eskalasi kritis memerlukan CFO.');
                }$s->query('freight_disputes')->where('id', $id)->update(['status' => 'RESOLVED', 'resolution' => $d['note'], 'resolved_by' => $s->actorId, 'updated_at' => now()]);
            }
            $s->event('freight.dispute_'.$d['action'], $id, ['note' => $d['note']]);
            app(FreightAuditService::class)->assess($s, $i->id);
        }, 3);

        return response()->json(['data' => ['status' => 'recorded']]);
    }
}
