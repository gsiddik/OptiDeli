<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class EsgCarbonTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_esg_carbon_calculator_segregates_scope1_and_scope3(): void
    {
        $tenantId = $this->signInTenantMember();

        // 1. Catat Trip Armada Sendiri (Scope 1)
        $res1 = $this->postJson('/api/v1/esg/calculate-trip', [
            'trip_id' => 'TRIP-OWN-01',
            'vehicle_id' => 'B-1001-OWN',
            'fleet_ownership' => 'INTERNAL',
            'fuel_type' => 'DIESEL_B35',
            'distance_km' => 200.0,
            'cargo_weight_ton' => 10.0, // 2000 ton-km * 110 g/ton-km / 1000 = 220 kg CO2e
        ]);

        $res1->assertStatus(201);
        $this->assertEquals('SCOPE_1', $res1->json('data.scope'));
        $this->assertEquals(220.0, $res1->json('data.total_co2e_kg'));

        // 2. Catat Trip Vendor Subkontraktor (Scope 3)
        $res2 = $this->postJson('/api/v1/esg/calculate-trip', [
            'trip_id' => 'TRIP-VND-02',
            'vehicle_id' => 'B-2002-VND',
            'fleet_ownership' => 'VENDOR',
            'fuel_type' => 'DIESEL_EURO4',
            'distance_km' => 100.0,
            'cargo_weight_ton' => 5.0, // 500 ton-km * 125 g/ton-km / 1000 = 62.5 kg CO2e
        ]);

        $res2->assertStatus(201);
        $this->assertEquals('SCOPE_3', $res2->json('data.scope'));
        $this->assertEquals(62.5, $res2->json('data.total_co2e_kg'));

        // 3. Verifikasi Dashboard Summary
        $resSummary = $this->getJson('/api/v1/esg/summary');

        $resSummary->assertStatus(200);
        $summary = $resSummary->json('data');

        $this->assertEquals(2, $summary['total_trips_logged']);
        $this->assertEquals(282.5, $summary['total_emissions_kg_co2e']);
        $this->assertEquals(220.0, $summary['scope_breakdown']['scope_1_kg']);
        $this->assertEquals(62.5, $summary['scope_breakdown']['scope_3_kg']);
    }
}
