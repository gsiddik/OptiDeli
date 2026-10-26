<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** An SPM assigned to a vendor's vehicle: the vendor's own driver, only verified ones, and the buying side from the vendor's rate for the vehicle type. */
class VendorAssignmentTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

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
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
        $this->m['cdd'] = $this->master('carrier_types', ['code' => 'CDD', 'name' => 'CDD Box']);
        $this->m['cde'] = $this->master('carrier_types', ['code' => 'CDE', 'name' => 'CDE Box']);
        $this->m['FREIGHT'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        $this->m['v1'] = $this->vendor('SEWA', 'CV Sewa');
        $this->m['v2'] = $this->vendor('LAIN', 'CV Lain');
        $this->vendorUser = $this->portalUser($this->m['v1']);
        $this->otherVendorUser = $this->portalUser($this->m['v2']);
        $this->m['own_vehicle'] = $this->vehicle('B 1 OWN');
        $this->m['own_driver'] = $this->driver('Own Driver', 'SIM-OWN');
        $this->m['vv'] = $this->vehicle('B 2 VND', $this->m['v1'], $this->m['cdd']);
        $this->m['vd'] = $this->driver('Vendor Driver', 'SIM-VND', $this->m['v1']);
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

    private function vehicle(string $plate, ?string $vendor = null, ?string $type = null, string $verification = 'VERIFIED'): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $id, 'tenant_id' => $this->tenant, 'plate' => $plate, 'type' => 'Box', 'capacity_g' => 1_000_000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE',
            'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now(), 'vendor_id' => $vendor, 'carrier_type_id' => $type, 'verification_status' => $verification,
            'verified_by' => $verification === 'VERIFIED' && $vendor ? $this->supervisor->id : null,
        ]);

        return $id;
    }

    private function driver(string $name, string $sim, ?string $vendor = null, string $verification = 'VERIFIED'): string
    {
        $id = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $id, 'tenant_id' => $this->tenant, 'name' => $name, 'license_number' => $sim, 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addYear(),
            'active' => true, 'vendor_id' => $vendor, 'verification_status' => $verification, 'verified_by' => $verification === 'VERIFIED' && $vendor ? $this->supervisor->id : null,
        ]);

        return $id;
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

    private function tariff(string $side, int $freight, array $extra = [], array $line = []): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', $extra + ['code' => 'T'.Str::random(5), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", $line + ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $freight])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
    }

    /** A planned SPM with one AWB, selling at 2 jt and, for the tenant's own fleet, buying at 1 jt. */
    private function spm(): array
    {
        $this->tariff('SELL', 2_000_000_00);
        $this->tariff('BUY', 1_000_000_00);
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm", ['lines' => [['material' => 'Paket A', 'quantity' => 1, 'weight_kg' => 100, 'volume_cbm' => 1]]])->assertCreated()->json('data');
        $awb = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/awbs", ['line_ids' => [$spm['lines'][0]['id']], 'destination_location_id' => $this->m['wh']])->assertCreated()->json('data');
        $this->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => 'PLANNED'])->assertOk();

        return ['id' => $spm['spm']['id'], 'awb' => $awb['id']];
    }

    private function vendorRate(string $vendor, string $carrier, int $price): void
    {
        $this->tariff('BUY', $price, ['vendor_id' => $vendor], ['carrier_type_id' => $carrier]);
    }

    private function trip(array $spm, string $vehicle, string $driver, array $o = [])
    {
        return $this->as($this->dispatcher)->postJson('/api/v1/spm-trips', $o + [
            'spm_id' => $spm['id'], 'awb_ids' => [$spm['awb']], 'vehicle_id' => $vehicle, 'driver_id' => $driver,
            'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(),
        ]);
    }

    private function buyCharges(string $spm): array
    {
        return DB::table('spm_charges')->where('transport_order_id', $spm)->where('side', 'BUY')->pluck('amount_minor')->map(fn ($v) => (int) $v)->all();
    }

    public function test_a_vendor_vehicle_assigns_the_spm_to_the_vendor_and_prices_the_buying_side_from_its_rate(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        $this->assertSame([1_000_000_00], $this->buyCharges($spm['id']), 'before: the own-fleet buying tariff');

        $r = $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertCreated();
        $this->assertSame($this->m['v1'], $r->json('data.trip.vendor_id'));
        $this->assertSame($this->m['v1'], DB::table('transport_orders')->where('id', $spm['id'])->value('vendor_id'));
        $this->assertSame([1_300_000_00], $this->buyCharges($spm['id']), 'after: the vendor rate for the vehicle type');
        $this->assertSame(1_300_000_00, (int) $r->json('data.trip.cost_minor'));
        $this->assertSame([], $r->json('data.warnings'));
        $this->assertSame(2_000_000_00, (int) $r->json('data.trip.sell_minor'));
    }

    public function test_without_a_vendor_rate_for_the_vehicle_type_the_planner_says_so_and_keeps_the_buying_side(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cde'], 1_800_000_00);
        $spm = $this->spm();
        $r = $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertCreated();
        $this->assertTrue(collect($r->json('data.warnings'))->contains(fn ($w) => str_contains($w, 'CDD Box')));
        $this->assertSame([1_000_000_00], $this->buyCharges($spm['id']));
    }

    public function test_only_verified_active_vendor_vehicles_and_drivers_are_assigned(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        $pending = $this->vehicle('B 3 PND', $this->m['v1'], $this->m['cdd'], 'PENDING');
        $this->trip($spm, $pending, $this->m['vd'])->assertUnprocessable();
        $this->trip($spm, $this->m['vv'], $this->driver('Baru', 'SIM-NEW', $this->m['v1'], 'PENDING'))->assertUnprocessable();
        DB::table('vehicles')->where('id', $this->m['vv'])->update(['active' => false]);
        $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertUnprocessable();
        DB::table('vehicles')->where('id', $this->m['vv'])->update(['active' => true]);
        $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertCreated();
    }

    public function test_a_vendor_vehicle_goes_with_that_vendors_driver_and_the_tenants_own_fleet_is_not_mixed(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        $this->trip($spm, $this->m['vv'], $this->m['own_driver'])->assertUnprocessable()->assertJsonValidationErrors('driver_id');
        $this->trip($spm, $this->m['own_vehicle'], $this->m['vd'])->assertUnprocessable()->assertJsonValidationErrors('driver_id');
        $other = $this->driver('Driver Lain', 'SIM-LAIN', $this->m['v2']);
        $this->trip($spm, $this->m['vv'], $other)->assertUnprocessable()->assertJsonValidationErrors('driver_id');
        $this->trip($spm, $this->m['vv'], $this->m['vd'], ['vendor_id' => $this->m['v2']])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $this->trip($spm, $this->m['own_vehicle'], $this->m['own_driver'])->assertCreated()->assertJsonPath('data.trip.vendor_id', null);
    }

    public function test_an_spm_of_another_vendor_is_refused(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        DB::table('transport_orders')->where('id', $spm['id'])->update(['vendor_id' => $this->m['v2']]);
        $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_a_verified_buying_charge_is_not_priced_again(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        DB::table('spm_charges')->where('transport_order_id', $spm['id'])->where('side', 'BUY')->update(['status' => 'VERIFIED', 'verified_by' => $this->supervisor->id, 'verified_at' => now()]);
        $r = $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertCreated();
        $this->assertTrue(collect($r->json('data.warnings'))->contains(fn ($w) => str_contains($w, 'sudah diverifikasi')));
        $this->assertSame([1_000_000_00], $this->buyCharges($spm['id']));
    }

    public function test_the_vendor_sees_the_trips_assigned_to_it_and_never_the_selling_side(): void
    {
        $this->vendorRate($this->m['v1'], $this->m['cdd'], 1_300_000_00);
        $spm = $this->spm();
        $this->trip($spm, $this->m['vv'], $this->m['vd'])->assertCreated();

        $portal = $this->as($this->vendorUser)->getJson('/api/v1/vendor/portal')->assertOk()->json('data');
        $this->assertCount(1, $portal['spm_trips']);
        $t = $portal['spm_trips'][0];
        $this->assertSame(['B 2 VND', 'Vendor Driver', 1_300_000_00], [$t['plate'], $t['driver_name'], (int) $t['cost_minor']]);
        $this->assertArrayNotHasKey('sell_minor', $t);
        $this->assertStringNotContainsString('PT Maju', json_encode($portal['spm_trips']));
        $this->as($this->otherVendorUser)->getJson('/api/v1/vendor/portal')->assertJsonCount(0, 'data.spm_trips');
    }
}
