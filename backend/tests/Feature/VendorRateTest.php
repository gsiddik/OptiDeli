<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** A vendor's buying rate per vehicle type: proposed by the vendor or entered by the tenant, approved by the tenant, then used to price. */
class VendorRateTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $head;

    private User $vendorUser;

    private User $otherVendorUser;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->head = $this->member('head_of_logistics');
        $this->m['cdd'] = $this->master('carrier_types', ['code' => 'CDD', 'name' => 'CDD Box']);
        $this->m['cde'] = $this->master('carrier_types', ['code' => 'CDE', 'name' => 'CDE Box']);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
        $this->m['freight'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        $this->m['v1'] = $this->vendor('SEWA', 'CV Sewa');
        $this->m['v2'] = $this->vendor('LAIN', 'CV Lain');
        $this->vendorUser = $this->portalUser($this->m['v1']);
        $this->otherVendorUser = $this->portalUser($this->m['v2']);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function vendor(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => $name, 'code' => $code, 'active' => true]);

        return $id;
    }

    private function portalUser(string $vendor): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => 'vendor', 'vendor_id' => $vendor, 'active' => true]);

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

    private function proposal(array $o = [], ?User $as = null)
    {
        return $this->as($as ?? $this->vendorUser)->postJson('/api/v1/vendor/rates', $o + ['name' => 'Tarif CV Sewa', 'valid_from' => now()->toDateString()]);
    }

    private function line(string $id, array $o = [], ?User $as = null)
    {
        return $this->as($as ?? $this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/lines", $o + [
            'carrier_type_id' => $this->m['cdd'], 'charge_code_id' => $this->m['freight'], 'basis' => 'TRIP', 'unit_price_minor' => 1_500_000_00,
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
        ]);
    }

    private function submitted(): string
    {
        $id = $this->proposal()->assertCreated()->json('data.id');
        $this->line($id)->assertCreated();
        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertOk();

        return $id;
    }

    public function test_a_vendor_proposes_a_rate_per_vehicle_type_and_submits_it(): void
    {
        $r = $this->proposal()->assertCreated();
        $this->assertMatchesRegularExpression('/^VR-SEWA-\d{4}$/', $r->json('data.code'));
        $this->assertSame([$this->m['v1'], 'BUY', 'DRAFT', true], [$r->json('data.vendor_id'), $r->json('data.side'), $r->json('data.status'), (bool) $r->json('data.proposed_by_vendor')]);
        $id = $r->json('data.id');

        $this->line($id, ['carrier_type_id' => null])->assertUnprocessable()->assertJsonValidationErrors('carrier_type_id');
        $this->line($id, ['unit_price_minor' => -1])->assertUnprocessable();
        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertUnprocessable();
        $line = $this->line($id)->assertCreated()->json('data');
        $this->assertSame($this->m['cdd'], $line['carrier_type_id']);
        $this->line($id)->assertUnprocessable();
        $this->line($id, ['carrier_type_id' => $this->m['cde'], 'unit_price_minor' => 2_000_000_00])->assertCreated();

        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertOk()->assertJsonPath('data.submitted_at', fn ($v) => $v !== null);
        $list = $this->as($this->vendorUser)->getJson('/api/v1/vendor/rates')->assertOk()->json('data');
        $this->assertSame(['SUBMITTED', 'VENDOR', 2], [$list[0]['state'], $list[0]['origin'], $list[0]['line_count']]);
        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertConflict();
        $this->line($id, ['carrier_type_id' => $this->m['cde'], 'basis' => 'KG'])->assertConflict();
    }

    public function test_the_tenant_approves_a_submitted_proposal_but_does_not_edit_it(): void
    {
        $id = $this->proposal()->json('data.id');
        $this->line($id)->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/approve")->assertConflict();

        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertOk();
        $this->as($this->supervisor)->putJson("/api/v1/tariffs/{$id}", ['name' => 'Diubah', 'valid_from' => now()->toDateString()])->assertConflict();
        $lineId = DB::table('rate_lines')->where('rate_table_id', $id)->value('id');
        $this->as($this->supervisor)->putJson("/api/v1/tariffs/{$id}/lines/{$lineId}", ['basis' => 'TRIP', 'unit_price_minor' => 1, 'charge_code_id' => $this->m['freight']])->assertConflict();
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$id}/approve")->assertForbidden();

        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->as($this->vendorUser)->getJson('/api/v1/vendor/rates')->assertJsonPath('data.0.state', 'APPROVED');
        $this->line($id)->assertConflict();
    }

    public function test_a_proposal_sent_back_returns_to_the_vendor_with_the_reason(): void
    {
        $id = $this->submitted();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/reject", ['note' => 'x'])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$id}/reject", ['note' => 'Harga belum disepakati'])->assertForbidden();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/reject", ['note' => 'Harga belum disepakati'])->assertOk();

        $row = $this->as($this->vendorUser)->getJson('/api/v1/vendor/rates')->json('data.0');
        $this->assertSame(['RETURNED', 'Harga belum disepakati'], [$row['state'], $row['review_note']]);
        $lineId = DB::table('rate_lines')->where('rate_table_id', $id)->value('id');
        $this->as($this->vendorUser)->putJson("/api/v1/vendor/rates/{$id}/lines/{$lineId}", ['carrier_type_id' => $this->m['cdd'], 'charge_code_id' => $this->m['freight'], 'basis' => 'TRIP', 'unit_price_minor' => 1_400_000_00])->assertOk();
        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/submit")->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/approve")->assertOk();
        $this->assertSame(1_400_000_00, (int) DB::table('rate_lines')->where('rate_table_id', $id)->value('unit_price_minor'));
    }

    public function test_a_vendor_can_withdraw_a_submission_and_cannot_see_other_vendors_rates(): void
    {
        $id = $this->submitted();
        $this->as($this->otherVendorUser)->getJson("/api/v1/vendor/rates/{$id}")->assertNotFound();
        $this->as($this->otherVendorUser)->getJson('/api/v1/vendor/rates')->assertJsonCount(0, 'data');
        $this->as($this->otherVendorUser)->postJson("/api/v1/vendor/rates/{$id}/withdraw")->assertNotFound();

        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/withdraw")->assertOk()->assertJsonPath('data.submitted_at', null);
        $this->line($id, ['carrier_type_id' => $this->m['cde']])->assertCreated();
    }

    public function test_a_rate_entered_by_the_tenant_shows_to_the_vendor_once_approved_and_is_read_only_there(): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'BUY-SEWA', 'name' => 'Tarif beli CV Sewa', 'side' => 'BUY', 'vendor_id' => $this->m['v1'], 'valid_from' => now()->toDateString()])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['carrier_type_id' => $this->m['cdd'], 'charge_code_id' => $this->m['freight'], 'basis' => 'TRIP', 'unit_price_minor' => 1_600_000_00])->assertCreated();
        $this->as($this->vendorUser)->getJson('/api/v1/vendor/rates')->assertJsonCount(0, 'data');
        $this->as($this->vendorUser)->getJson("/api/v1/vendor/rates/{$t}")->assertNotFound();

        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
        $row = $this->as($this->vendorUser)->getJson('/api/v1/vendor/rates')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['TENANT', 'APPROVED'], [$row['origin'], $row['state']]);
        $this->as($this->vendorUser)->getJson("/api/v1/vendor/rates/{$t}")->assertOk()->assertJsonPath('data.lines.0.unit_price_minor', 1_600_000_00);
        $this->line($t)->assertConflict();
        $this->as($this->vendorUser)->putJson("/api/v1/vendor/rates/{$t}", ['name' => 'X', 'valid_from' => now()->toDateString()])->assertConflict();
        $this->as($this->otherVendorUser)->getJson("/api/v1/vendor/rates/{$t}")->assertNotFound();
    }

    public function test_an_approved_rate_is_what_prices_the_vendors_vehicle_type(): void
    {
        $id = $this->submitted();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/approve")->assertOk();

        $buy = fn (string $carrier) => $this->as($this->head)->postJson('/api/v1/tariffs/resolve', [
            'vendor_id' => $this->m['v1'], 'carrier_type_id' => $carrier, 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'], 'date' => now()->toDateString(),
        ])->assertOk()->json('data.lines.0.buy');
        $this->assertSame(1_500_000_00, $buy($this->m['cdd'])['amount_minor']);
        $this->assertNull($buy($this->m['cde']), 'no rate for a type the vendor did not price');
    }

    public function test_a_new_version_of_an_approved_rate_is_proposed_again(): void
    {
        $id = $this->submitted();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$id}/approve")->assertOk();
        $this->as($this->vendorUser)->postJson("/api/v1/vendor/rates/{$id}/versions", ['valid_from' => now()->addMonth()->toDateString()])->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.proposed_by_vendor', true)->assertJsonPath('data.submitted_at', null);
        $this->as($this->otherVendorUser)->postJson("/api/v1/vendor/rates/{$id}/versions", ['valid_from' => now()->addMonth()->toDateString()])->assertNotFound();
    }

    public function test_the_vendor_picks_from_its_options_and_internal_screens_stay_closed(): void
    {
        $o = $this->as($this->vendorUser)->getJson('/api/v1/vendor/rate-options')->assertOk()->json('data');
        $this->assertContains($this->m['cdd'], array_column($o['carrier_types'], 'id'));
        $this->assertContains($this->m['freight'], array_column($o['charge_codes'], 'id'));
        $this->as($this->vendorUser)->getJson('/api/v1/tariffs')->assertForbidden();
        $this->as($this->dispatcher)->getJson('/api/v1/vendor/rates')->assertForbidden();
    }
}
