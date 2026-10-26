<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Customer\CustomerReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Read-only view of one customer's own shipments and invoices. Nothing here exposes buying prices, vendors, drivers or margins. */
class CustomerPortalController extends Controller
{
    /** Provisioned by staff from the customer record. */
    public function account(Request $r, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        $s = OrderController::storeFor($r);
        DB::transaction(function () use ($s, $d, $id) {
            $customer = $s->find('customers', $id);
            abort_unless($customer->active, 422, 'Pelanggan tidak aktif.');
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            DB::table('memberships')->insert(['tenant_id' => $s->tenantId, 'user_id' => $u->id, 'role' => 'customer', 'customer_id' => $id, 'active' => true]);
            $s->event('customer.account_created', $id, ['user_id' => $u->id]);
        });

        return response()->json(['data' => ['status' => 'created']], 201);
    }

    private function reader(Request $r): CustomerReader
    {
        return new CustomerReader($r->attributes->get('tenant')->id, $r->attributes->get('customer_id'));
    }

    public function portal(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->summary()]);
    }

    public function spms(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->spms($r->query('search'), $r->query('status'))]);
    }

    public function spm(Request $r, string $id)
    {
        $d = $this->reader($r)->spm($id);
        abort_unless($d, 404);

        return response()->json(['data' => $d]);
    }

    public function invoices(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->invoices()]);
    }

    public function invoice(Request $r, string $id)
    {
        $d = $this->reader($r)->invoice($id);
        abort_unless($d, 404);

        return response()->json(['data' => $d]);
    }
}
