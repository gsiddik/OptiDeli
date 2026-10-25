<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** An OptiNexus tenant that gets OptiDeli assigned also gets its OptiDeli tenant, through the broker's signed webhook. */
class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const NEXUS_TENANT = '01a1735c-f279-72a5-9515-d77ffa586388';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'optinexus',
            'optideli.optinexus.events.webhook_secret' => self::SECRET, 'optideli.optinexus.application_code' => 'optideli',
        ]);
    }

    private function assigned(array $override = []): array
    {
        return array_replace_recursive([
            'event_id' => (string) Str::uuid(), 'event_type' => 'cgo.tenant.application_assigned', 'schema_version' => '1', 'source_system' => 'optinexus',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'tenant', 'aggregate_id' => self::NEXUS_TENANT, 'payload' => [
                'application_code' => 'optideli', 'application_name' => 'OptiDeli', 'tenant_code' => 'TEN-01M5SNSWKSAE1CBE4DANS9Y9YD', 'tenant_name' => 'Indovita',
            ]],
        ], $override);
    }

    private function deliver(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) ($timestamp ?? time());

        return $this->call('POST', '/api/v1/integration/optinexus/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $secret ?? self::SECRET),
        ], $body);
    }

    private function tenant(): ?object
    {
        return DB::table('tenants')->where('optinexus_tenant_id', self::NEXUS_TENANT)->first();
    }

    public function test_assigning_optideli_creates_the_linked_tenant_with_its_roles(): void
    {
        $this->deliver($this->assigned())->assertStatus(202)->assertJsonPath('result', 'tenant_created');

        $tenant = $this->tenant();
        $this->assertNotNull($tenant);
        $this->assertSame('indovita', $tenant->slug);
        $this->assertSame('Indovita', $tenant->name);
        $this->assertTrue((bool) $tenant->active);
        $this->assertGreaterThan(0, DB::table('roles')->where('tenant_id', $tenant->id)->count(), 'the tenant roles are created like in the Superadmin console');
        $this->assertSame(1, DB::table('platform_audit_events')->where('action', 'platform.tenant_provisioned_by_optinexus')->where('subject_id', $tenant->id)->count());
    }

    public function test_a_repeated_delivery_changes_nothing_and_keeps_what_a_superadmin_edited(): void
    {
        $this->deliver($this->assigned())->assertStatus(202);
        DB::table('tenants')->where('optinexus_tenant_id', self::NEXUS_TENANT)->update(['name' => 'Indovita Logistik', 'slug' => 'indovita-logistik']);

        $this->deliver($this->assigned())->assertStatus(202)->assertJsonPath('result', 'tenant_exists');

        $this->assertSame(1, DB::table('tenants')->where('optinexus_tenant_id', self::NEXUS_TENANT)->count());
        $this->assertSame('Indovita Logistik', $this->tenant()->name);
        $this->assertSame('indovita-logistik', $this->tenant()->slug);
    }

    public function test_an_already_linked_tenant_is_not_duplicated(): void
    {
        DB::table('tenants')->insert(['id' => (string) Str::uuid(), 'slug' => 'demo', 'name' => 'Demo', 'minimum_margin_bps' => 1500, 'policy_version' => 1, 'optinexus_tenant_id' => self::NEXUS_TENANT]);

        $this->deliver($this->assigned())->assertStatus(202)->assertJsonPath('result', 'tenant_exists');

        $this->assertSame(1, DB::table('tenants')->count());
    }

    public function test_a_clashing_slug_gets_a_suffix_and_an_odd_name_still_gives_a_valid_slug(): void
    {
        DB::table('tenants')->insert(['id' => (string) Str::uuid(), 'slug' => 'indovita', 'name' => 'Other', 'minimum_margin_bps' => 1500, 'policy_version' => 1]);

        $this->deliver($this->assigned())->assertStatus(202);
        $this->assertSame('indovita-2', $this->tenant()->slug);

        $other = '01a1735c-0000-7000-8000-000000000001';
        $this->deliver($this->assigned(['tenant_id' => $other, 'data' => ['payload' => ['tenant_name' => '###']]]))->assertStatus(202);
        $slug = DB::table('tenants')->where('optinexus_tenant_id', $other)->value('slug');
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
        $this->assertGreaterThanOrEqual(2, strlen($slug));
    }

    public function test_events_about_other_applications_or_other_types_create_nothing(): void
    {
        $this->deliver($this->assigned(['data' => ['payload' => ['application_code' => 'optiradar']]]))->assertStatus(202)->assertJsonPath('result', 'ignored');
        $this->deliver($this->assigned(['event_type' => 'cgo.tenant.application_removed']))->assertStatus(202)->assertJsonPath('result', 'ignored');

        $this->assertNull($this->tenant());
    }

    public function test_a_bad_signature_a_stale_timestamp_or_a_missing_secret_creates_nothing(): void
    {
        $this->deliver($this->assigned(), 'whsec_other')->assertStatus(401);
        $this->deliver($this->assigned(), null, time() - 3600)->assertStatus(401);
        config(['optideli.optinexus.events.webhook_secret' => '']);
        $this->deliver($this->assigned())->assertStatus(401);

        $this->assertNull($this->tenant());
    }

    public function test_a_tenant_id_that_is_not_a_uuid_creates_nothing_and_a_standalone_deployment_is_left_alone(): void
    {
        $this->deliver($this->assigned(['tenant_id' => 'not-a-uuid']))->assertStatus(202)->assertJsonPath('result', 'ignored');

        config(['optideli.deployment_mode' => 'standalone']);
        $this->deliver($this->assigned())->assertStatus(202)->assertJsonPath('result', 'ignored');

        $this->assertNull($this->tenant());
    }
}
