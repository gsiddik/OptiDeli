<?php

namespace Tests\Feature;

use App\Domain\Customer\Webhooks;
use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Customer API clients (scoped, rotatable tokens) and webhooks (signed, retried) (F6/M11). */
class CustomerIntegrationTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private const HOOK = 'https://93.184.216.34/hooks/optideli';

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $finance;

    private User $cfo;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->finance = $this->member('finance');
        $this->cfo = $this->member('cfo');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14]);
        $this->m['other'] = $this->master('customers', ['code' => 'LAIN', 'name' => 'PT Lain', 'payment_terms_days' => 14]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE']);
        $this->m['FREIGHT'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        $this->tariff('SELL', 2_000_000_00);
        $this->tariff('BUY', 1_000_000_00);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, $this->key())->assertCreated()->json('data.id');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    private function tariff(string $side, int $freight): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'T'.Str::random(5), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $freight])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
    }

    private function draftSpm(?string $customer = null): string
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $customer ?? $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();

        return $this->postJson("/api/v1/orders/{$order}/spm", ['lines' => [['material' => 'Paket', 'quantity' => 1, 'weight_kg' => 100]]])->assertCreated()->json('data.spm.id');
    }

    private function move(string $spm, string $to): void
    {
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$spm}/status", ['to' => $to])->assertOk();
    }

    private function client(array $scopes = ['spm.read', 'invoice.read'], ?string $customer = null): array
    {
        return $this->as($this->cfo)->postJson('/api/v1/api-clients', ['customer_id' => $customer ?? $this->m['cust'], 'name' => 'ERP', 'scopes' => $scopes])->assertCreated()->json('data');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function hook(array $events = ['spm.status_changed', 'invoice.issued'], ?string $customer = null): array
    {
        return $this->as($this->cfo)->postJson('/api/v1/webhooks', ['customer_id' => $customer ?? $this->m['cust'], 'name' => 'ERP', 'url' => self::HOOK, 'events' => $events])->assertCreated()->json('data');
    }

    public function test_only_who_may_provision_manages_clients_and_the_token_is_shown_once(): void
    {
        $body = ['customer_id' => $this->m['cust'], 'name' => 'ERP', 'scopes' => ['spm.read']];
        $this->as($this->supervisor)->postJson('/api/v1/api-clients', $body)->assertForbidden();
        $this->as($this->cfo)->postJson('/api/v1/api-clients', ['scopes' => ['admin']] + $body)->assertUnprocessable();
        $issued = $this->as($this->cfo)->postJson('/api/v1/api-clients', $body)->assertCreated()->json('data');
        $this->assertStringStartsWith('odcl_', $issued['token']);

        $list = $this->as($this->cfo)->getJson('/api/v1/api-clients')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertStringNotContainsString($issued['token'], json_encode($list));
        $this->assertSame(['spm.read'], $list[0]['scopes']);
        $this->assertNotSame($issued['token'], DB::table('api_clients')->value('token_hash'));
        $this->as($this->supervisor)->getJson('/api/v1/api-clients')->assertForbidden();
    }

    public function test_a_token_reads_only_its_customer_and_only_within_its_scopes(): void
    {
        $mine = $this->draftSpm();
        $theirs = $this->draftSpm($this->m['other']);
        $this->move($mine, 'PLANNED');
        $this->move($theirs, 'PLANNED');
        $spmToken = $this->client(['spm.read'])['token'];

        $this->getJson('/api/v1/integration/customer/spm')->assertUnauthorized();
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer('odcl_salah'))->assertUnauthorized();
        $rows = $this->getJson('/api/v1/integration/customer/spm', $this->bearer($spmToken))->assertOk()->json('data');
        $this->assertSame([$mine], array_column($rows, 'id'));
        $this->getJson("/api/v1/integration/customer/spm/{$mine}", $this->bearer($spmToken))->assertOk()->assertJsonPath('data.spm.id', $mine);
        $this->getJson("/api/v1/integration/customer/spm/{$theirs}", $this->bearer($spmToken))->assertNotFound();
        $this->getJson('/api/v1/integration/customer/invoices', $this->bearer($spmToken))->assertForbidden()->assertJsonPath('code', 'API_FORBIDDEN_SCOPE');
        $this->postJson('/api/v1/integration/customer/spm', [], $this->bearer($spmToken))->assertStatus(405);
        $this->assertNotNull(DB::table('api_clients')->value('last_used_at'));
    }

    public function test_rotating_replaces_the_token_at_once_and_revoking_ends_it(): void
    {
        $c = $this->client();
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($c['token']))->assertOk();

        $new = $this->as($this->cfo)->postJson("/api/v1/api-clients/{$c['id']}/rotate")->assertOk()->json('data.token');
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($c['token']))->assertUnauthorized();
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($new))->assertOk();

        $this->as($this->cfo)->postJson("/api/v1/api-clients/{$c['id']}/revoke")->assertNoContent();
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($new))->assertUnauthorized();
        $this->as($this->cfo)->postJson("/api/v1/api-clients/{$c['id']}/rotate")->assertConflict();

        $expired = $this->client();
        DB::table('api_clients')->where('id', $expired['id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($expired['token']))->assertUnauthorized();
        $this->assertSame(3, DB::table('audit_events')->whereIn('action', ['customer.api_client_issued', 'customer.api_client_rotated', 'customer.api_client_revoked'])->count() - 1);
    }

    public function test_a_deactivated_customer_loses_api_access(): void
    {
        $c = $this->client();
        DB::table('customers')->where('id', $this->m['cust'])->update(['active' => false]);
        $this->getJson('/api/v1/integration/customer/spm', $this->bearer($c['token']))->assertUnauthorized();
    }

    public function test_webhook_targets_must_be_https_and_public(): void
    {
        $body = ['customer_id' => $this->m['cust'], 'name' => 'ERP', 'events' => ['invoice.issued']];
        foreach (['http://93.184.216.34/x', 'https://10.0.0.5/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://user:pw@93.184.216.34/x'] as $url) {
            $this->assertSame(422, $this->as($this->cfo)->postJson('/api/v1/webhooks', $body + ['url' => $url])->status(), $url);
        }
        $this->as($this->cfo)->postJson('/api/v1/webhooks', ['url' => self::HOOK, 'events' => ['nonsense']] + $body)->assertUnprocessable();
        $this->as($this->supervisor)->postJson('/api/v1/webhooks', $body + ['url' => self::HOOK])->assertForbidden();
        $created = $this->as($this->cfo)->postJson('/api/v1/webhooks', $body + ['url' => self::HOOK])->assertCreated()->json('data');
        $this->assertStringStartsWith('whsec_', $created['secret']);
        $this->assertSame($created['secret'], Crypt::decryptString(DB::table('webhook_subscriptions')->value('secret')));
        $this->assertStringNotContainsString($created['secret'], json_encode($this->as($this->cfo)->getJson('/api/v1/webhooks')->json()));
    }

    public function test_a_status_change_is_delivered_signed_to_the_customers_own_subscriptions_only(): void
    {
        $mine = $this->hook(['spm.status_changed']);
        $this->hook(['invoice.issued']);
        $this->hook(['spm.status_changed'], $this->m['other']);
        $spm = $this->draftSpm();
        $this->assertSame(0, DB::table('webhook_deliveries')->count(), 'a draft is not announced');
        $this->move($spm, 'PLANNED');
        $this->assertSame(1, DB::table('webhook_deliveries')->count());
        $this->assertSame($mine['id'], DB::table('webhook_deliveries')->value('subscription_id'));

        Http::fake(['93.184.216.34/*' => Http::response('ok', 200)]);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'dead' => 0], app(Webhooks::class)->dispatchDue());
        Http::assertSent(function ($request) use ($mine, $spm) {
            $timestamp = $request->header('X-OptiDeli-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $mine['secret']);
            $payload = json_decode($request->body(), true);

            return $request->url() === self::HOOK && $request->header('X-OptiDeli-Signature')[0] === $expected && $request->header('X-OptiDeli-Event')[0] === 'spm.status_changed'
                && $payload['data']['spm']['id'] === $spm && $payload['data']['spm']['to'] === 'PLANNED' && ! str_contains($request->body(), 'reason');
        });
        $this->assertSame('SENT', DB::table('webhook_deliveries')->value('status'));
        $this->assertSame(['sent' => 0, 'failed' => 0, 'dead' => 0], app(Webhooks::class)->dispatchDue(), 'a sent delivery is not sent again');
    }

    public function test_failures_are_retried_with_a_growing_delay_then_given_up_and_can_be_retried_by_hand(): void
    {
        $sub = $this->hook(['spm.status_changed']);
        $spm = $this->draftSpm();
        $this->move($spm, 'PLANNED');
        $answer = 500;
        Http::fake(['93.184.216.34/*' => function () use (&$answer) {
            return Http::response('', $answer);
        }]);

        $this->assertSame(['sent' => 0, 'failed' => 1, 'dead' => 0], app(Webhooks::class)->dispatchDue());
        $row = DB::table('webhook_deliveries')->first();
        $this->assertSame([1, 500, 'PENDING'], [$row->attempts, $row->last_status_code, $row->status]);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, strtotime($row->next_attempt_at), 5);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'dead' => 0], app(Webhooks::class)->dispatchDue(), 'not due yet');

        $dead = null;
        for ($i = 2; $i <= count(Webhooks::BACKOFF) + 1; $i++) {
            DB::table('webhook_deliveries')->update(['next_attempt_at' => now()->subSecond()]);
            $dead = app(Webhooks::class)->dispatchDue();
        }
        $this->assertSame(['sent' => 0, 'failed' => 0, 'dead' => 1], $dead);
        $id = DB::table('webhook_deliveries')->value('id');
        $this->assertSame('DEAD', DB::table('webhook_deliveries')->value('status'));

        $this->as($this->supervisor)->postJson("/api/v1/webhook-deliveries/{$id}/retry")->assertForbidden();
        $this->as($this->cfo)->postJson("/api/v1/webhook-deliveries/{$id}/retry")->assertNoContent();
        $answer = 204;
        $this->assertSame(['sent' => 1, 'failed' => 0, 'dead' => 0], app(Webhooks::class)->dispatchDue());
        $this->as($this->cfo)->postJson("/api/v1/webhook-deliveries/{$id}/retry")->assertConflict();
        $this->assertCount(1, $this->as($this->cfo)->getJson("/api/v1/webhooks/{$sub['id']}/deliveries")->json('data'));
    }

    public function test_a_disabled_subscription_gets_nothing_and_a_new_secret_replaces_the_old(): void
    {
        $sub = $this->hook(['spm.status_changed']);
        $this->as($this->cfo)->putJson("/api/v1/webhooks/{$sub['id']}/active", ['active' => false])->assertNoContent();
        $this->move($this->draftSpm(), 'PLANNED');
        $this->assertSame(0, DB::table('webhook_deliveries')->count());

        $new = $this->as($this->cfo)->postJson("/api/v1/webhooks/{$sub['id']}/rotate-secret")->assertOk()->json('data.secret');
        $this->assertNotSame($sub['secret'], $new);
        $this->assertSame($new, Crypt::decryptString(DB::table('webhook_subscriptions')->value('secret')));
    }

    public function test_issuing_an_invoice_announces_it_to_the_customer(): void
    {
        $this->hook(['invoice.issued']);
        $spm = $this->draftSpm();
        foreach (['PLANNED', 'LOADING', 'IN_TRANSIT', 'DISCHARGED'] as $to) {
            $this->move($spm, $to);
        }
        DB::table('awbs')->where('transport_order_id', $spm)->update(['status' => 'DELIVERED']);
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertOk();
        $invoice = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data.invoices.0');

        $delivery = DB::table('webhook_deliveries')->where('event_type', 'invoice.issued')->first();
        $this->assertNotNull($delivery);
        $payload = json_decode($delivery->payload, true);
        $this->assertSame([$invoice['id'], $invoice['number'], $invoice['amount_minor']], [$payload['data']['invoice']['id'], $payload['data']['invoice']['number'], $payload['data']['invoice']['amount_minor']]);
    }
}
