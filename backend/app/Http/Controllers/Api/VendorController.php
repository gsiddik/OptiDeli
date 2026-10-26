<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Access\Seats;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class VendorController extends Controller
{
    public function index(Request $r)
    {
        return response()->json(['data' => OrderController::storeFor($r)->query('vendors')->orderBy('name')->get()]);
    }

    public function create(Request $r)
    {
        OrderController::writeAccess($r);
        $d = $r->validate(['name' => 'required|string|max:200', 'code' => 'required|string|max:80']);
        $s = OrderController::storeFor($r);
        $v = $s->idempotent('vendor.create', OrderController::key($r), $d, function () use ($s, $d) {
            $id = (string) Str::uuid();
            DB::table('vendors')->insert([...$d, 'id' => $id, 'tenant_id' => $s->tenantId, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $s->event('vendor.created', $id);

            return $s->find('vendors', $id);
        });

        return response()->json(['data' => $v], 201);
    }

    public function account(Request $r, string $id)
    {
        Access::authorize($r, 'vendor.portal.provision');
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $d, $id) {
            $s->find('vendors', $id);
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            DB::table('memberships')->insert(['tenant_id' => $s->tenantId, 'user_id' => $u->id, 'role' => 'vendor', 'vendor_id' => $id, 'active' => true]);
            $s->event('vendor.account_created', $id, ['user_id' => $u->id]);
        });
        Seats::snapshot($s->tenantId);

        return response()->json(['data' => ['status' => 'created']], 201);
    }

    /** The sign-in accounts of one vendor; each active one takes a seat of the tenant. */
    public function accounts(Request $r, string $id)
    {
        Access::authorize($r, 'vendor.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('vendors', $id);

        return response()->json(['data' => DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('memberships.tenant_id', $s->tenantId)->where('memberships.vendor_id', $id)
            ->orderBy('users.name')->get(['memberships.id', 'users.name', 'users.email', 'memberships.active'])]);
    }

    /** Switching an account off ends its sessions and frees its seat; switching it on takes the seat again. */
    public function setAccountActive(Request $r, string $id, int $membership)
    {
        Access::authorize($r, 'vendor.portal.provision');
        $d = $r->validate(['active' => 'required|boolean']);
        $s = OrderController::storeFor($r);
        $s->find('vendors', $id);
        DB::transaction(function () use ($s, $id, $membership, $d) {
            $row = DB::table('memberships')->where('tenant_id', $s->tenantId)->where('vendor_id', $id)->where('id', $membership)->lockForUpdate()->first() ?? abort(404);
            DB::table('memberships')->where('id', $row->id)->update(['active' => $d['active']]);
            if (! $d['active']) {
                DB::table('users')->where('id', $row->user_id)->increment('session_epoch');
            }
            $s->event($d['active'] ? 'vendor.account_enabled' : 'vendor.account_disabled', $id, ['user_id' => $row->user_id]);
        });
        Seats::snapshot($s->tenantId);

        return response()->noContent();
    }

    public function portal(Request $r)
    {
        abort_unless(Access::isVendor($r), 403);
        $s = OrderController::storeFor($r);
        $vendor = $r->attributes->get('vendor_id');
        $trips = $s->query('trips')->where('vendor_id', $vendor)->get(['id', 'status', 'contract_reference']);
        $ids = $trips->pluck('id');
        $invoices = $s->query('freight_invoices')->where('vendor_id', $vendor)->orderByDesc('created_at')->limit(100)->get()->map(function ($i) {
            unset($i->path);

            return $i;
        });
        $disputes = $s->query('freight_disputes')->whereIn('invoice_id', $invoices->pluck('id'))->orderByDesc('created_at')->get();
        $pods = $s->query('proofs_of_delivery')->whereIn('trip_id', $ids)->get(['id', 'trip_id', 'status', 'receiver_name', 'outcome']);

        // The SPM trips the tenant assigned to this vendor's vehicles. The vendor sees the move and what it will be paid, never the selling side.
        $spmTrips = $s->query('spm_trips')->where('vendor_id', $vendor)->orderByDesc('starts_at')->limit(100)->get(['id', 'number', 'transport_order_id', 'vehicle_id', 'driver_id', 'status', 'starts_at', 'ends_at', 'cost_minor']);
        $orders = $s->query('transport_orders')->whereIn('id', $spmTrips->pluck('transport_order_id'))->get(['id', 'number', 'origin_text', 'destination_text'])->keyBy('id');
        $plates = $s->query('vehicles')->whereIn('id', $spmTrips->pluck('vehicle_id'))->pluck('plate', 'id');
        $names = $s->query('drivers')->whereIn('id', $spmTrips->pluck('driver_id'))->pluck('name', 'id');
        foreach ($spmTrips as $t) {
            $o = $orders[$t->transport_order_id] ?? null;
            $t->spm_number = $o?->number;
            $t->route = $o ? $o->origin_text.' → '.$o->destination_text : null;
            $t->plate = $plates[$t->vehicle_id] ?? null;
            $t->driver_name = $names[$t->driver_id] ?? null;
            unset($t->transport_order_id, $t->vehicle_id, $t->driver_id);
        }

        return response()->json(['data' => ['vendor' => $s->find('vendors', $vendor), 'trips' => $trips, 'spm_trips' => $spmTrips, 'invoices' => $invoices, 'disputes' => $disputes, 'pods' => $pods]]);
    }
}
