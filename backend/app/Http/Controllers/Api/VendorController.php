<?php

namespace App\Http\Controllers\Api;

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
        abort_unless($r->attributes->get('role') === 'cfo', 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $d, $id) {
            $s->find('vendors', $id);
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            DB::table('memberships')->insert(['tenant_id' => $s->tenantId, 'user_id' => $u->id, 'role' => 'vendor', 'vendor_id' => $id, 'active' => true]);
            $s->event('vendor.account_created', $id, ['user_id' => $u->id]);
        });

        return response()->json(['data' => ['status' => 'created']], 201);
    }

    public function portal(Request $r)
    {
        abort_unless($r->attributes->get('role') === 'vendor', 403);
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

        return response()->json(['data' => ['vendor' => $s->find('vendors', $vendor), 'trips' => $trips, 'invoices' => $invoices, 'disputes' => $disputes, 'pods' => $pods]]);
    }
}
