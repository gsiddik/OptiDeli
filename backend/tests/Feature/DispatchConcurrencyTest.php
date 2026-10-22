<?php

namespace Tests\Feature;

use App\Domain\Dispatch\DispatchService;
use App\Domain\Shared\TenantStore;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DispatchConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_workers_cannot_double_allocate_vehicle_and_driver(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real PostgreSQL row locks required; run the PostgreSQL suite.');
        }
        $tenant = (string) Str::uuid();
        $vehicle = (string) Str::uuid();
        $driver = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'race', 'name' => 'Race']);
        DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $tenant, 'plate' => 'RACE-1', 'type' => 'Box', 'capacity_g' => 1000000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
        DB::table('drivers')->insert(['id' => $driver, 'tenant_id' => $tenant, 'name' => 'Driver', 'license_number' => 'RACE', 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDay(), 'active' => true]);
        $plans = [];
        $store = new TenantStore($tenant, $user->id);
        for ($i = 0; $i < 2; $i++) {
            $order = (string) Str::uuid();
            DB::table('orders')->insert(['id' => $order, 'tenant_id' => $tenant, 'number' => 'RACE-'.$i, 'customer_name' => 'Test', 'stage' => 'FIRST_MILE', 'origin' => 'A', 'destination' => 'B', 'currency' => 'IDR', 'base_price_minor' => 100000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'sell_minor' => 100000, 'status' => 'CONFIRMED', 'created_by' => $user->id]);
            DB::table('order_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'order_id' => $order, 'description' => 'Box', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]);
            $plans[] = app(DispatchService::class)->create($store, ['order_id' => $order, 'vehicle_id' => $vehicle, 'driver_id' => $driver, 'vendor_base_minor' => 80000, 'vendor_accessorial_minor' => 0, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(4)->toIso8601String(), 'load_safety_attestation' => 'Test fixture load safety attestation'], (string) Str::uuid())->id;
        }
        $barrier = tempnam(sys_get_temp_dir(), 'opti-race-');
        unlink($barrier);
        $processes = [];
        try {
            foreach ($plans as $plan) {
                $p = new Process([PHP_BINARY, base_path('tests/support/dispatch-worker.php'), $tenant, (string) $user->id, $plan, $barrier], base_path(), ['APP_ENV' => 'testing']);
                $p->setTimeout(20);
                $p->start();
                $processes[] = $p;
            }
            file_put_contents($barrier, 'go');
            $statuses = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $statuses[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertDatabaseCount('trips', 1);
            $this->assertDatabaseCount('outbox_events', 3);
            $this->assertSame(1, DB::table('orders')->where('status', 'DISPATCHED')->count());
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            } @unlink($barrier);
        }
    }
}
