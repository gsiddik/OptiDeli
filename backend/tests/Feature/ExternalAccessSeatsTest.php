<?php

namespace Tests\Feature;

use App\Domain\Access\Seats;
use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Vendors' and customers' users keep their own accounts here while the staff sign in through OptiNexus; vendors' users take seats of the tenant. */
class ExternalAccessSeatsTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private const PASSWORD = 'Rahasia-Eksternal-123!';

    private string $tenant;

    private string $slug;

    private string $vendor;

    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('cfo');
        MasterDefaults::seed($this->tenant);
        $this->slug = (string) DB::table('tenants')->where('id', $this->tenant)->value('slug');
        $this->vendor = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $this->vendor, 'tenant_id' => $this->tenant, 'name' => 'CV Sewa', 'code' => 'SEWA', 'active' => true]);
        $this->customer = $this->postJson('/api/v1/master/customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        auth()->logout();
        $this->app['session']->flush();
    }

    private function sso(): void
    {
        config(['optideli.identity_mode' => 'optinexus', 'optideli.optinexus.base_url' => 'http://nexus.test', 'optideli.optinexus.service.client_id' => 'svc', 'optideli.optinexus.service.client_secret' => 'secret']);
        Cache::flush();
    }

    private function external(string $role, array $bind): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true] + $bind);

        return $user;
    }

    private function login(User $user, ?string $tenant = null)
    {
        return $this->postJson('/api/v1/auth/external-login', ['email' => $user->email, 'password' => self::PASSWORD, 'tenant' => $tenant ?? $this->slug]);
    }

    public function test_in_optinexus_mode_a_vendor_user_signs_in_with_its_own_password_and_gets_its_local_permissions(): void
    {
        $this->sso();
        $vendorUser = $this->external('vendor', ['vendor_id' => $this->vendor]);
        $this->postJson('/api/v1/auth/login', ['email' => $vendorUser->email, 'password' => self::PASSWORD, 'tenant' => $this->slug])->assertForbidden();

        $this->login($vendorUser)->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permissions', ['vendor.portal']);
        $this->getJson('/api/v1/vendor/fleet')->assertOk();
        $this->getJson('/api/v1/spm')->assertForbidden();
    }

    public function test_a_customer_user_signs_in_the_same_way_and_the_staff_do_not_use_this_door(): void
    {
        $this->sso();
        $customerUser = $this->external('customer', ['customer_id' => $this->customer]);
        $this->login($customerUser)->assertOk();
        $this->getJson('/api/v1/customer/portal')->assertOk();
        $this->postJson('/api/v1/auth/logout');

        $staff = User::factory()->create(['password' => self::PASSWORD]);
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $staff->id, 'role' => 'cfo', 'active' => true]);
        $this->login($staff)->assertUnprocessable();
        $this->login($customerUser, 'tidak-ada')->assertUnprocessable();
        $this->postJson('/api/v1/auth/external-login', ['email' => $customerUser->email, 'password' => 'salah-salah-12345', 'tenant' => $this->slug])->assertUnprocessable();
    }

    public function test_a_password_session_of_staff_is_not_accepted_in_optinexus_mode(): void
    {
        $this->sso();
        $staff = User::factory()->create(['password' => self::PASSWORD]);
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $staff->id, 'role' => 'cfo', 'active' => true]);
        $this->actingAs($staff)->withSession(['tenant_id' => $this->tenant, 'session_epoch' => (int) $staff->session_epoch])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_deactivated_vendor_user_cannot_sign_in(): void
    {
        $this->sso();
        $vendorUser = $this->external('vendor', ['vendor_id' => $this->vendor]);
        DB::table('memberships')->where('user_id', $vendorUser->id)->update(['active' => false]);
        $this->login($vendorUser)->assertUnprocessable();
    }

    public function test_seats_are_the_people_who_run_the_tenant_and_its_vendors_users_but_not_customers(): void
    {
        $base = Seats::used($this->tenant);
        $this->assertSame(1, $base['internal']);
        $this->external('vendor', ['vendor_id' => $this->vendor]);
        $this->external('vendor', ['vendor_id' => $this->vendor]);
        $this->external('customer', ['customer_id' => $this->customer]);
        $inactive = $this->external('vendor', ['vendor_id' => $this->vendor]);
        DB::table('memberships')->where('user_id', $inactive->id)->update(['active' => false]);

        $used = Seats::used($this->tenant);
        $this->assertSame([1, 2, 3], [$used['internal'], $used['vendor'], $used['total']]);
    }

    public function test_the_month_keeps_the_peak_even_when_a_user_is_removed(): void
    {
        $a = $this->external('vendor', ['vendor_id' => $this->vendor]);
        $this->external('vendor', ['vendor_id' => $this->vendor]);
        Seats::snapshot($this->tenant);
        DB::table('memberships')->where('user_id', $a->id)->update(['active' => false]);
        Seats::snapshot($this->tenant);

        $this->assertSame(3, (int) DB::table('seat_snapshots')->where('tenant_id', $this->tenant)->value('total'));
        $this->assertSame(2, Seats::used($this->tenant)['total']);
    }

    public function test_the_limit_of_optinexus_is_what_is_included_and_adding_beyond_it_is_never_refused(): void
    {
        $this->sso();
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => '01a17253-693b-7282-a91c-98585b90e59d']);
        Http::fake([
            '*/api/v1/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/commercial-context' => Http::response(['success' => true, 'data' => ['tenant_id' => '01a17253-693b-7282-a91c-98585b90e59d', 'resource_limits' => ['user_limit' => '2']]]),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->external('vendor', ['vendor_id' => $this->vendor]);
        }
        $cfo = DB::table('memberships')->where('tenant_id', $this->tenant)->where('role', 'cfo')->first();

        $summary = app(Seats::class)->summary($this->tenant);
        $this->assertSame([4, 2, 2], [$summary['total'], $summary['included'], $summary['above_included']]);
        $this->assertNotNull($cfo);
    }

    public function test_the_finished_month_is_reported_once_as_usage_and_a_failure_is_tried_again(): void
    {
        $this->sso();
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => '01a17253-693b-7282-a91c-98585b90e59d']);
        $last = now()->subMonthNoOverflow();
        DB::table('seat_snapshots')->insert([
            ['tenant_id' => $this->tenant, 'day' => $last->copy()->day(3)->toDateString(), 'internal' => 2, 'vendor' => 1, 'total' => 3, 'updated_at' => now()],
            ['tenant_id' => $this->tenant, 'day' => $last->copy()->day(20)->toDateString(), 'internal' => 2, 'vendor' => 4, 'total' => 6, 'updated_at' => now()],
            ['tenant_id' => $this->tenant, 'day' => now()->toDateString(), 'internal' => 2, 'vendor' => 9, 'total' => 11, 'updated_at' => now()],
        ]);
        Http::fake([
            '*/api/v1/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/usage-events' => Http::sequence()->push(['success' => false], 503)->push(['success' => true], 201),
        ]);

        $this->assertSame(['sent' => 0, 'failed' => 1], app(Seats::class)->report());
        $this->assertSame('FAILED', DB::table('seat_reports')->where('period', $last->format('Y-m'))->value('status'));
        $this->assertSame(['sent' => 1, 'failed' => 0], app(Seats::class)->report());
        $this->assertSame(['sent' => 0, 'failed' => 0], app(Seats::class)->report(), 'a reported month is not sent again');

        Http::assertSent(function ($request) use ($last) {
            if (! str_ends_with($request->url(), '/usage-events')) {
                return false;
            }
            $b = $request->data();

            return $b['meter_key'] === 'user_seat' && $b['quantity'] === 6 && $b['tenant_id'] === '01a17253-693b-7282-a91c-98585b90e59d'
                && $b['idempotency_key'] === 'optideli-seats-'.$last->format('Y-m') && $b['application_code'] === 'optideli';
        });
        $this->assertSame(0, DB::table('seat_reports')->where('period', now()->format('Y-m'))->count(), 'the running month is not reported yet');
    }

    public function test_only_who_manages_users_sees_the_seats(): void
    {
        $this->actingAs(User::find(DB::table('memberships')->where('tenant_id', $this->tenant)->value('user_id')))->withSession(['tenant_id' => $this->tenant])->getJson('/api/v1/seats')->assertOk()->assertJsonPath('data.internal', 1);
        $dispatcher = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $dispatcher->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($dispatcher)->withSession(['tenant_id' => $this->tenant])->getJson('/api/v1/seats')->assertForbidden();
    }

    public function test_the_staff_switch_a_vendors_account_off_and_on_and_the_seat_follows(): void
    {
        $vendorUser = $this->external('vendor', ['vendor_id' => $this->vendor]);
        $cfo = User::find(DB::table('memberships')->where('tenant_id', $this->tenant)->where('role', 'cfo')->value('user_id'));
        $as = fn (User $u) => $this->actingAs($u)->withSession(['tenant_id' => $this->tenant]);

        $list = $as($cfo)->getJson("/api/v1/vendors/{$this->vendor}/accounts")->assertOk()->json('data');
        $this->assertSame([$vendorUser->email, true], [$list[0]['email'], (bool) $list[0]['active']]);
        $this->assertSame(1, Seats::used($this->tenant)['vendor']);

        $as($cfo)->putJson("/api/v1/vendors/{$this->vendor}/accounts/{$list[0]['id']}/active", ['active' => false])->assertNoContent();
        $this->assertSame(0, Seats::used($this->tenant)['vendor']);
        $this->login($vendorUser)->assertUnprocessable();
        $as($cfo)->putJson("/api/v1/vendors/{$this->vendor}/accounts/{$list[0]['id']}/active", ['active' => true])->assertNoContent();
        $this->assertSame(1, Seats::used($this->tenant)['vendor']);
        $this->login($vendorUser)->assertOk();
        $this->flushSession();

        $other = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $other, 'tenant_id' => $this->tenant, 'name' => 'CV Lain', 'code' => 'LAIN', 'active' => true]);
        $as($cfo)->putJson("/api/v1/vendors/{$other}/accounts/{$list[0]['id']}/active", ['active' => false])->assertNotFound();
        $dispatcher = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $dispatcher->id, 'role' => 'dispatcher', 'active' => true]);
        $as($dispatcher)->getJson("/api/v1/vendors/{$this->vendor}/accounts")->assertForbidden();
    }
}
