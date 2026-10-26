<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Customer\ApiClients;
use App\Domain\Customer\CustomerReader;
use App\Domain\Customer\Webhooks;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Two faces of the customer integration: staff manage API clients and webhooks (session, customer.portal.provision), and the customer's
 * own systems read with a scoped token (bearer odcl_..., read-only, nothing that is internal).
 */
class CustomerIntegrationController extends Controller
{
    // ---------------------------------------------------------------- staff: API clients

    public function clients(Request $r)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $names = $s->query('customers')->pluck('name', 'id');
        $rows = $s->query('api_clients')->orderByDesc('created_at')->get(['id', 'customer_id', 'name', 'token_prefix', 'scopes', 'expires_at', 'revoked_at', 'rotated_at', 'last_used_at', 'created_at']);
        foreach ($rows as $row) {
            $row->scopes = json_decode($row->scopes, true);
            $row->customer_label = $names[$row->customer_id] ?? null;
        }

        return response()->json(['data' => $rows]);
    }

    public function issueClient(Request $r, ApiClients $clients)
    {
        Access::authorize($r, 'customer.portal.provision');
        $d = $r->validate([
            'customer_id' => 'required|uuid', 'name' => 'required|string|max:80', 'scopes' => 'required|array|min:1', 'scopes.*' => ['required', Rule::in(ApiClients::SCOPES)],
            'expires_at' => 'nullable|date|after:today',
        ]);
        $s = OrderController::storeFor($r);
        $customer = $s->find('customers', $d['customer_id']);
        abort_unless($customer->active, 422, 'Pelanggan tidak aktif.');
        $issued = DB::transaction(function () use ($clients, $s, $d) {
            $issued = $clients->issue($s->tenantId, $d['customer_id'], $d['name'], $d['scopes'], $s->actorId, $d['expires_at'] ?? null);
            $s->event('customer.api_client_issued', $issued['id'], ['customer_id' => $d['customer_id'], 'name' => $d['name'], 'scopes' => $d['scopes']]);

            return $issued;
        });

        return response()->json(['data' => $issued], 201)->header('Cache-Control', 'no-store');
    }

    public function rotateClient(Request $r, ApiClients $clients, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('api_clients', $id);
        $token = DB::transaction(function () use ($clients, $s, $id) {
            $token = $clients->rotate($s->tenantId, $id);
            abort_unless($token, 409, 'Klien API sudah dicabut.');
            $s->event('customer.api_client_rotated', $id);

            return $token;
        });

        return response()->json(['data' => ['id' => $id, 'token' => $token]])->header('Cache-Control', 'no-store');
    }

    public function revokeClient(Request $r, ApiClients $clients, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('api_clients', $id);
        DB::transaction(function () use ($clients, $s, $id) {
            abort_unless($clients->revoke($s->tenantId, $id), 409, 'Klien API sudah dicabut.');
            $s->event('customer.api_client_revoked', $id);
        });

        return response()->noContent();
    }

    // ---------------------------------------------------------------- staff: webhooks

    public function webhooks(Request $r)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $names = $s->query('customers')->pluck('name', 'id');
        $counts = $s->query('webhook_deliveries')->selectRaw('subscription_id, status, count(*) as n')->groupBy('subscription_id', 'status')->get()->groupBy('subscription_id');
        $rows = $s->query('webhook_subscriptions')->orderByDesc('created_at')->get(['id', 'customer_id', 'name', 'url', 'events', 'active', 'created_at']);
        foreach ($rows as $row) {
            $row->events = json_decode($row->events, true);
            $row->customer_label = $names[$row->customer_id] ?? null;
            $row->deliveries = ($counts[$row->id] ?? collect())->pluck('n', 'status');
        }

        return response()->json(['data' => $rows]);
    }

    public function subscribe(Request $r, Webhooks $webhooks)
    {
        Access::authorize($r, 'customer.portal.provision');
        $d = $r->validate([
            'customer_id' => 'required|uuid', 'name' => 'required|string|max:80', 'url' => 'required|url|max:500',
            'events' => 'required|array|min:1', 'events.*' => ['required', Rule::in(Webhooks::EVENTS)],
        ]);
        $s = OrderController::storeFor($r);
        abort_unless($s->find('customers', $d['customer_id'])->active, 422, 'Pelanggan tidak aktif.');
        $created = DB::transaction(function () use ($webhooks, $s, $d) {
            $created = $webhooks->subscribe($s->tenantId, $d['customer_id'], $d['name'], $d['url'], $d['events'], $s->actorId);
            $s->event('customer.webhook_created', $created['id'], ['customer_id' => $d['customer_id'], 'url' => $d['url'], 'events' => $d['events']]);

            return $created;
        });

        return response()->json(['data' => $created], 201)->header('Cache-Control', 'no-store');
    }

    public function rotateSecret(Request $r, Webhooks $webhooks, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('webhook_subscriptions', $id);
        $secret = DB::transaction(function () use ($webhooks, $s, $id) {
            $secret = $webhooks->rotateSecret($s->tenantId, $id);
            $s->event('customer.webhook_secret_rotated', $id);

            return $secret;
        });

        return response()->json(['data' => ['id' => $id, 'secret' => $secret]])->header('Cache-Control', 'no-store');
    }

    public function setActive(Request $r, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $d = $r->validate(['active' => 'required|boolean']);
        $s = OrderController::storeFor($r);
        $s->find('webhook_subscriptions', $id);
        DB::transaction(function () use ($s, $id, $d) {
            DB::table('webhook_subscriptions')->where('tenant_id', $s->tenantId)->where('id', $id)->update(['active' => $d['active'], 'updated_at' => now()]);
            $s->event($d['active'] ? 'customer.webhook_enabled' : 'customer.webhook_disabled', $id);
        });

        return response()->noContent();
    }

    public function deliveries(Request $r, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('webhook_subscriptions', $id);

        return response()->json(['data' => $s->query('webhook_deliveries')->where('subscription_id', $id)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'event_type', 'status', 'attempts', 'next_attempt_at', 'last_status_code', 'last_error', 'delivered_at', 'created_at'])]);
    }

    public function retry(Request $r, Webhooks $webhooks, string $id)
    {
        Access::authorize($r, 'customer.portal.provision');
        $s = OrderController::storeFor($r);
        $s->find('webhook_deliveries', $id);
        abort_unless($webhooks->retry($s->tenantId, $id), 409, 'Hanya pengiriman yang gagal permanen yang dapat diulang.');

        return response()->noContent();
    }

    // ---------------------------------------------------------------- the customer's own systems

    private function reader(Request $r): CustomerReader
    {
        $c = $r->attributes->get('api_client');

        return new CustomerReader($c->tenant_id, $c->customer_id);
    }

    public function spms(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->spms($r->query('search'), $r->query('status'))])->header('Cache-Control', 'no-store');
    }

    public function spm(Request $r, string $id)
    {
        $d = $this->reader($r)->spm($id);
        abort_unless($d, 404);

        return response()->json(['data' => $d])->header('Cache-Control', 'no-store');
    }

    public function invoices(Request $r)
    {
        return response()->json(['data' => $this->reader($r)->invoices()])->header('Cache-Control', 'no-store');
    }

    public function invoice(Request $r, string $id)
    {
        $d = $this->reader($r)->invoice($id);
        abort_unless($d, 404);

        return response()->json(['data' => $d])->header('Cache-Control', 'no-store');
    }
}
