<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** K5: the OptiRadar map in OptiDeli comes from an embed session minted by OptiNexus, never from a Radar credential held here. */
class RadarEmbedTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    private const NEXUS = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember();
        config([
            'optideli.optinexus.base_url' => 'https://nexus.test',
            'optideli.optinexus.service.client_id' => 'cid', 'optideli.optinexus.service.client_secret' => 'secret',
        ]);
        Cache::flush();
    }

    private function link(?string $id = self::NEXUS): void
    {
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => $id]);
    }

    public function test_the_session_is_minted_by_optinexus_for_the_linked_tenant(): void
    {
        $this->link();
        Http::fake([
            'nexus.test/api/v1/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'nexus.test/api/v1/embed/sessions' => Http::response(['data' => ['url' => 'https://radar.test/?embed=1&token=abc', 'expires_at' => '2026-10-24T20:00:00+00:00']], 201),
        ]);

        $this->postJson('/api/v1/telematics/radar-embed')->assertOk()
            ->assertJsonPath('data.url', 'https://radar.test/?embed=1&token=abc');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/token') && $r['scope'] === 'embed.create');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/embed/sessions') && $r['tenant_id'] === self::NEXUS && $r['target'] === 'optiradar');
    }

    public function test_a_tenant_that_is_not_linked_gets_a_clear_conflict(): void
    {
        $this->link(null);
        Http::fake();

        $this->postJson('/api/v1/telematics/radar-embed')->assertStatus(409)->assertJsonPath('code', 'RADAR_EMBED_NOT_AVAILABLE');
        Http::assertNothingSent();
    }

    public function test_no_entitlement_and_an_unreachable_nexus_are_told_apart(): void
    {
        $this->link();
        $answer = [403, ['code' => 'EMBED_TARGET_NOT_ENTITLED']];
        Http::fake([
            'nexus.test/api/v1/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'nexus.test/api/v1/embed/sessions' => function () use (&$answer) {
                return Http::response($answer[1], $answer[0]);
            },
        ]);

        $this->postJson('/api/v1/telematics/radar-embed')->assertForbidden()->assertJsonPath('code', 'RADAR_EMBED_NOT_ENTITLED');

        $answer = [502, ['code' => 'EMBED_UNAVAILABLE']];
        $this->postJson('/api/v1/telematics/radar-embed')->assertStatus(503)->assertJsonPath('code', 'RADAR_EMBED_UNAVAILABLE');
    }
}
