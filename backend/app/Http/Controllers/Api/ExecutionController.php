<?php

namespace App\Http\Controllers\Api;

use App\Domain\Execution\FreightAuditService;
use App\Domain\Execution\Permissions;
use App\Domain\Execution\PrivateEvidence;
use App\Domain\Execution\TripService;
use App\Domain\Execution\YardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExecutionController extends Controller
{
    public function trips(Request $r)
    {
        $s = OrderController::storeFor($r);

        return response()->json($s->query('trips')->orderByDesc('created_at')->paginate(25));
    }

    public function show(Request $r, string $id)
    {
        $s = OrderController::storeFor($r);
        $trip = $s->find('trips', $id);
        $trip->order = $s->find('orders', $trip->order_id);
        $trip->plan = $s->find('dispatch_plans', $trip->plan_id);
        $trip->legs = $s->query('trip_legs')->where('trip_id', $id)->orderBy('sequence')->get();
        $trip->pods = $s->query('proofs_of_delivery')->where('trip_id', $id)->get()->map(function ($p) {
            unset($p->path);

            return $p;
        });
        $trip->claims = $s->query('accessorial_claims')->where('trip_id', $id)->get();

        return response()->json(['data' => $trip]);
    }

    public function transition(Request $r, string $id, TripService $service)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['action' => ['required', Rule::in(['start', 'complete', 'cancel'])], 'reason' => 'required_if:action,cancel|nullable|string|min:10|max:2000']);

        return response()->json(['data' => $service->transition(OrderController::storeFor($r), $id, $d['action'], $d['reason'] ?? null)]);
    }

    public function leg(Request $r, string $id, TripService $service)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['origin' => 'required|string|max:300', 'destination' => 'required|string|max:300', 'planned_eta' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);

        return response()->json(['data' => $service->addLeg(OrderController::storeFor($r), $id, $d)], 201);
    }

    public function legTransition(Request $r, string $id, TripService $service)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['action' => ['required', Rule::in(['depart', 'arrive'])]]);

        return response()->json(['data' => $service->legTransition(OrderController::storeFor($r), $id, $d['action'])]);
    }

    public function vendor(Request $r, string $id)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['vendor_id' => 'required|uuid', 'contract_reference' => 'required|string|min:3|max:200']);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $id, $d) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $s->find('trips', $id, true);
            abort_unless($trip->status === 'ASSIGNED', 409, 'Vendor/kontrak dikunci setelah trip berangkat.');
            $v = $s->find('vendors', $d['vendor_id']);
            abort_unless($v->active, 422);
            $s->query('trips')->where('id', $id)->update([...$d, 'updated_at' => now()]);
            $s->event('trip.vendor_bound', $id, $d);
        }, 3);

        return response()->json(['data' => $s->find('trips', $id)]);
    }

    public function yard(Request $r)
    {
        $s = OrderController::storeFor($r);

        return response()->json(['data' => ['docks' => $s->query('docks')->orderBy('hub')->orderBy('name')->get(), 'appointments' => $s->query('dock_appointments')->orderByDesc('starts_at')->limit(100)->get()]]);
    }

    public function dock(Request $r)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'hub' => 'required|string|max:150']);
        $s = OrderController::storeFor($r);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($s, $d, $id) {
            DB::table('docks')->insert([...$d, 'id' => $id, 'tenant_id' => $s->tenantId]);
            $s->event('yard.dock_registered', $id);
        });

        return response()->json(['data' => $s->find('docks', $id)], 201);
    }

    public function book(Request $r, YardService $service)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['dock_id' => 'required|uuid', 'trip_id' => 'required|uuid', 'starts_at' => ['required', 'date', 'after_or_equal:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'], 'ends_at' => ['required', 'date', 'after:starts_at', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);

        return response()->json(['data' => $service->book(OrderController::storeFor($r), $d, OrderController::key($r))], 201);
    }

    public function yardTransition(Request $r, string $id, YardService $service)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['action' => ['required', Rule::in(['gate_in', 'dock_in', 'dock_out', 'gate_out', 'cancel'])]]);

        return response()->json(['data' => $service->transition(OrderController::storeFor($r), $id, $d['action'])]);
    }

    public function pod(Request $r, string $id)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['receiver_name' => 'required|string|max:150', 'delivered_units' => 'required|integer|min:0|max:100000', 'outcome' => ['required', Rule::in(['DELIVERED', 'EXCEPTION'])], 'note' => 'required_if:outcome,EXCEPTION|nullable|string|max:2000', 'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240']);
        $file = $r->file('file');
        unset($d['file']);
        $d['sha256'] = hash_file('sha256', $file->getRealPath());
        $s = OrderController::storeFor($r);
        $podId = (string) Str::uuid();
        $path = $s->tenantId.'/pods/'.$podId;
        try {
            $pod = $s->idempotent('pod.create', OrderController::key($r), [...$d, 'trip_id' => $id], function () use ($s, $id, $d, $file, $podId, $path) {
                $trip = $s->find('trips', $id, true);
                abort_unless($trip->status === 'ARRIVED', 409, 'ePOD hanya dapat diunggah setelah semua leg tiba.');
                $expected = (int) $s->query('order_items')->where('order_id', $trip->order_id)->sum('quantity');
                abort_if($d['delivered_units'] > $expected || ($d['outcome'] === 'DELIVERED' && $d['delivered_units'] !== $expected), 422, 'Jumlah terkirim tidak sesuai order; gunakan exception bila tidak lengkap.');
                abort_unless(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 500, 'Penyimpanan bukti gagal.');
                DB::table('proofs_of_delivery')->insert([...$d, 'id' => $podId, 'tenant_id' => $s->tenantId, 'trip_id' => $id, 'path' => $path, 'mime' => $file->getMimeType(), 'status' => 'SUBMITTED', 'submitted_by' => $s->actorId, 'created_at' => now(), 'updated_at' => now()]);
                $s->event('pod.submitted', $podId, ['trip_id' => $id, 'sha256' => $d['sha256']]);

                return $s->find('proofs_of_delivery', $podId);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        unset($pod->path);

        return response()->json(['data' => $pod], 201);
    }

    public function verifyPod(Request $r, string $id)
    {
        Permissions::verifier($r);
        $d = $r->validate(['decision' => ['required', Rule::in(['verify', 'reject'])], 'note' => 'required|string|min:10|max:2000']);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $id, $d) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $pod = $s->find('proofs_of_delivery', $id, true);
            abort_unless($pod->status === 'SUBMITTED', 409);
            abort_unless(PrivateEvidence::valid($pod), 422, 'Berkas bukti hilang atau integritasnya tidak sesuai.');
            abort_if($pod->submitted_by === $s->actorId, 403, 'Pengunggah tidak dapat memverifikasi bukti sendiri.');
            $s->query('proofs_of_delivery')->where('id', $id)->update(['status' => $d['decision'] === 'verify' ? 'VERIFIED' : 'REJECTED', 'verified_by' => $s->actorId, 'verified_at' => now(), 'updated_at' => now()]);
            $s->event('pod.'.$d['decision'], $id, ['note' => $d['note']]);
            app(FreightAuditService::class)->refreshTrip($s, $pod->trip_id);
        }, 3);

        return response()->json(['data' => ['status' => 'recorded']]);
    }

    public function file(Request $r, string $kind, string $id)
    {
        $s = OrderController::storeFor($r);
        $table = match ($kind) {
            'pods' => 'proofs_of_delivery','invoices' => 'freight_invoices',default => abort(404)
        };
        $record = $s->find($table, $id);
        if ($r->attributes->get('role') === 'vendor') {
            $trip = $s->find('trips', $record->trip_id);
            abort_unless($trip->vendor_id === $r->attributes->get('vendor_id'), 404);
        }
        abort_unless(Storage::disk('local')->exists($record->path), 404);

        return Storage::disk('local')->download($record->path, $kind.'-'.$id.match ($record->mime) {
            'application/pdf' => '.pdf','image/png' => '.png',default => '.jpg'
        }, ['Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
