<?php

namespace Tests\Feature;

use App\Models\VendorRateCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class RateCardEstimateTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_estimate_uses_decimal_arithmetic_with_half_up_rounding(): void
    {
        $tenant = $this->signInTenantMember();
        VendorRateCard::create(['tenant_id' => $tenant, 'vendor_id' => 'VND-A', 'vehicle_type' => 'CDD', 'zone_origin' => 'A', 'zone_destination' => 'B', 'base_rate_minor_units' => 1000, 'rate_per_km_minor_units' => 100, 'min_distance_km' => 0, 'is_active' => true]);

        // 1.005 km x 100 = 100.5 minor units; float arithmetic gives 100.49999... and rounds down.
        $this->postJson('/api/v1/rate-cards/calculate-estimate', ['vendor_id' => 'VND-A', 'vehicle_type' => 'CDD', 'zone_origin' => 'A', 'zone_destination' => 'B', 'distance_km' => '1.005'])
            ->assertOk()
            ->assertJsonPath('estimated_cost_minor_units', 1101)
            ->assertJsonPath('estimated_cost_idr', '11.01');
    }
}
