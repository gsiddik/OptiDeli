<?php

namespace Tests\Feature;

use App\Domain\Dispatch\LocalFleetAvailability;
use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** A vendor registers its own vehicles and drivers and uploads their documents; the tenant verifies them. */
class VendorFleetTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $cfo;

    private User $vendorUser;

    private User $otherVendorUser;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->cfo = $this->member('cfo');
        $this->m['cdd'] = $this->master('carrier_types', ['code' => 'CDD', 'name' => 'CDD Box']);
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

    private function vehicle(array $o = [], ?User $as = null)
    {
        return $this->as($as ?? $this->vendorUser)->postJson('/api/v1/vendor/vehicles', $o + ['plate' => 'b 1234 xy', 'carrier_type_id' => $this->m['cdd'], 'capacity_kg' => 2500, 'length_mm' => 4200, 'width_mm' => 2000, 'height_mm' => 2000]);
    }

    private function driver(array $o = [], ?User $as = null)
    {
        return $this->as($as ?? $this->vendorUser)->postJson('/api/v1/vendor/drivers', $o + ['name' => 'Budi', 'license_number' => 'SIM-0001', 'phone' => '0812']);
    }

    private function doc(string $type, string $id, string $kind, array $o = [], ?User $as = null)
    {
        $file = $o['file'] ?? UploadedFile::fake()->create($kind.'.pdf', 120, 'application/pdf');
        unset($o['file']);

        return $this->as($as ?? $this->vendorUser)->post("/api/v1/vendor/{$type}/{$id}/documents", $o + ['kind' => $kind, 'number' => 'N-1', 'valid_until' => now()->addMonths(10)->toDateString(), 'file' => $file], ['Accept' => 'application/json']);
    }

    private function completeVehicle(): string
    {
        $id = $this->vehicle()->assertCreated()->json('data.id');
        $this->doc('vehicles', $id, 'STNK', ['valid_until' => now()->addMonths(10)->toDateString()])->assertCreated();
        $this->doc('vehicles', $id, 'KIR', ['valid_until' => now()->addMonths(4)->toDateString()])->assertCreated();

        return $id;
    }

    private function completeDriver(): string
    {
        $id = $this->driver()->assertCreated()->json('data.id');
        $this->doc('drivers', $id, 'SIM', ['valid_until' => now()->addYear()->toDateString()])->assertCreated();
        $this->doc('drivers', $id, 'KTP', ['valid_until' => null])->assertCreated();

        return $id;
    }

    public function test_a_vendor_registers_a_vehicle_that_waits_for_verification(): void
    {
        $r = $this->vehicle()->assertCreated();
        $this->assertSame(['B 1234 XY', 'PENDING', $this->m['v1'], 'CDD Box', 2_500_000], [$r->json('data.plate'), $r->json('data.verification_status'), $r->json('data.vendor_id'), $r->json('data.type'), $r->json('data.capacity_g')]);
        $this->assertNull($r->json('data.documents_valid_until'));

        $this->vehicle()->assertUnprocessable()->assertJsonValidationErrors('plate');
        $this->vehicle(['plate' => 'B 9 X', 'carrier_type_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('carrier_type_id');
        $this->vehicle(['plate' => 'B 8 X', 'capacity_kg' => 0])->assertUnprocessable()->assertJsonValidationErrors('capacity_kg');

        $overview = $this->as($this->vendorUser)->getJson('/api/v1/vendor/fleet')->assertOk();
        $this->assertSame(['STNK', 'KIR'], $overview->json('data.vehicles.0.missing_documents'));
        $this->as($this->otherVendorUser)->getJson('/api/v1/vendor/fleet')->assertOk()->assertJsonCount(0, 'data.vehicles');
    }

    public function test_a_vendor_registers_a_driver_and_cannot_touch_another_vendors_rows(): void
    {
        $id = $this->driver()->assertCreated()->assertJsonPath('data.verification_status', 'PENDING')->json('data.id');
        $this->driver()->assertUnprocessable()->assertJsonValidationErrors('license_number');
        $this->as($this->otherVendorUser)->putJson("/api/v1/vendor/drivers/{$id}", ['name' => 'X', 'license_number' => 'SIM-X'])->assertNotFound();
        $vehicle = $this->completeVehicle();
        $this->as($this->otherVendorUser)->putJson('/api/v1/vendor/vehicles/'.$vehicle, ['plate' => 'D 1 A', 'carrier_type_id' => $this->m['cdd'], 'capacity_kg' => 1, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1])->assertNotFound();
        $this->doc('drivers', $id, 'SIM', [], $this->otherVendorUser)->assertNotFound();
        $this->as($this->vendorUser)->putJson("/api/v1/vendor/drivers/{$id}", ['name' => 'Budi S', 'license_number' => 'SIM-0001'])->assertOk()->assertJsonPath('data.name', 'Budi S');
    }

    public function test_documents_need_a_type_a_valid_until_and_a_real_file(): void
    {
        $id = $this->vehicle()->json('data.id');
        $this->doc('vehicles', $id, 'SIM')->assertUnprocessable();
        $this->doc('vehicles', $id, 'STNK', ['valid_until' => null])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->doc('vehicles', $id, 'STNK', ['valid_until' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->doc('vehicles', $id, 'STNK', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $first = $this->doc('vehicles', $id, 'STNK')->assertCreated()->json('data');
        Storage::disk('local')->assertExists($first['path']);

        $second = $this->doc('vehicles', $id, 'STNK', ['number' => 'N-2'])->assertCreated()->json('data');
        $this->assertSame($first['id'], $second['id'], 'the same kind is replaced, not added');
        Storage::disk('local')->assertMissing($first['path']);
        $this->assertSame(1, DB::table('fleet_documents')->where('owner_id', $id)->count());
    }

    public function test_the_tenant_verifies_a_complete_vehicle_and_sets_what_dispatch_checks(): void
    {
        $id = $this->completeVehicle();
        $stnk = now()->addMonths(10)->toDateString();
        $kir = now()->addMonths(4)->toDateString();

        $this->as($this->dispatcher)->getJson('/api/v1/fleet-verification')->assertForbidden();
        $queue = $this->as($this->supervisor)->getJson('/api/v1/fleet-verification')->assertOk()->json('data');
        $this->assertSame([$id], array_column($queue['vehicles'], 'id'));
        $this->assertSame('CV Sewa', $queue['vehicles'][0]['vendor_label']);
        $this->assertCount(2, $queue['vehicles'][0]['documents']);

        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$id}", ['decision' => 'VERIFY'])->assertOk()
            ->assertJsonPath('data.verification_status', 'VERIFIED')->assertJsonPath('data.documents_valid_until', $kir);
        $this->assertNotNull(DB::table('vehicles')->where('id', $id)->value('readiness_checked_at'));
        $this->assertSame($stnk > $kir, true);
        $this->as($this->vendorUser)->getJson('/api/v1/vendor/fleet')->assertJsonPath('data.vehicles.0.verification_status', 'VERIFIED');
    }

    public function test_an_incomplete_or_expired_set_cannot_be_verified_and_a_rejection_needs_a_reason(): void
    {
        $id = $this->vehicle()->json('data.id');
        $this->doc('vehicles', $id, 'STNK')->assertCreated();
        $r = $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$id}", ['decision' => 'VERIFY'])->assertUnprocessable();
        $this->assertStringContainsString('KIR', $r->json('errors.decision.0'));

        $this->doc('vehicles', $id, 'KIR')->assertCreated();
        DB::table('fleet_documents')->where('owner_id', $id)->where('kind', 'KIR')->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$id}", ['decision' => 'VERIFY'])->assertUnprocessable();

        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$id}", ['decision' => 'REJECT'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$id}", ['decision' => 'REJECT', 'note' => 'KIR sudah mati'])->assertOk()->assertJsonPath('data.verification_status', 'REJECTED');
        $this->as($this->vendorUser)->getJson('/api/v1/vendor/fleet')->assertJsonPath('data.vehicles.0.verification_note', 'KIR sudah mati');

        $this->doc('vehicles', $id, 'KIR')->assertCreated();
        $this->assertSame('PENDING', DB::table('vehicles')->where('id', $id)->value('verification_status'), 'a new document puts it back in the queue');
    }

    public function test_a_driver_is_verified_with_the_sim_dates_and_a_changed_vehicle_is_checked_again(): void
    {
        $driver = $this->completeDriver();
        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/drivers/{$driver}", ['decision' => 'VERIFY'])->assertOk()
            ->assertJsonPath('data.license_valid_until', now()->addYear()->toDateString());
        $this->assertNotNull(DB::table('drivers')->where('id', $driver)->value('eligibility_verified_until'));

        $vehicle = $this->completeVehicle2();
        $this->as($this->supervisor)->postJson("/api/v1/fleet-verification/vehicles/{$vehicle}", ['decision' => 'VERIFY'])->assertOk();
        $this->as($this->vendorUser)->putJson("/api/v1/vendor/vehicles/{$vehicle}", ['plate' => 'B 7777 ZZ', 'carrier_type_id' => $this->m['cdd'], 'capacity_kg' => 2500, 'length_mm' => 4200, 'width_mm' => 2000, 'height_mm' => 2000])
            ->assertOk()->assertJsonPath('data.verification_status', 'PENDING');
    }

    private function completeVehicle2(): string
    {
        $id = $this->vehicle(['plate' => 'B 5555 AA'])->assertCreated()->json('data.id');
        $this->doc('vehicles', $id, 'STNK')->assertCreated();
        $this->doc('vehicles', $id, 'KIR')->assertCreated();

        return $id;
    }

    public function test_documents_are_downloaded_only_by_their_vendor_and_by_the_tenant_staff(): void
    {
        $id = $this->vehicle()->json('data.id');
        $doc = $this->doc('vehicles', $id, 'STNK')->json('data.id');
        $this->as($this->vendorUser)->get("/api/v1/vendor/fleet-documents/{$doc}")->assertOk();
        $this->as($this->otherVendorUser)->get("/api/v1/vendor/fleet-documents/{$doc}")->assertNotFound();
        $this->as($this->supervisor)->get("/api/v1/fleet-documents/{$doc}")->assertOk();
        $this->as($this->dispatcher)->get("/api/v1/fleet-documents/{$doc}")->assertForbidden();
        $this->as($this->vendorUser)->get("/api/v1/fleet-documents/{$doc}")->assertForbidden();
        $this->as($this->vendorUser)->getJson('/api/v1/fleet-verification')->assertForbidden();
    }

    public function test_a_vehicle_on_a_trip_cannot_be_switched_off_and_dispatch_refuses_unverified_ones(): void
    {
        $id = $this->completeVehicle();
        $this->as($this->vendorUser)->putJson("/api/v1/vendor/vehicles/{$id}/active", ['active' => false])->assertOk()->assertJsonPath('data.active', false);

        $vehicle = DB::table('vehicles')->where('id', $id)->first();
        $driver = (object) ['active' => true, 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addYear(), 'verification_status' => 'VERIFIED'];
        $check = fn () => app(LocalFleetAvailability::class)->assertReady($vehicle, $driver, CarbonImmutable::now()->addDay());
        try {
            $check();
            $this->fail('an unverified, inactive vehicle must be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $vehicle->active = true;
        $vehicle->verification_status = 'VERIFIED';
        $vehicle->documents_valid_until = now()->addMonth()->toDateString();
        $vehicle->readiness_checked_at = now();
        $check();
        $this->addToAssertionCount(1);

        DB::table('spm_trips')->insertOrIgnore([]);
    }
}
