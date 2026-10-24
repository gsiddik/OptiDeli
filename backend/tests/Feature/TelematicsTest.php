<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class TelematicsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_telematics_ping_triggers_overspeed_and_reefer_alerts(): void
    {
        $tenantId = $this->signInTenantMember();

        // 1. Ingestion ping dengan overspeed dan suhu reefer bocor
        $response = $this->postJson('/api/v1/telematics/ping', [
            'trip_id' => 'TRIP-DEMO-01',
            'vehicle_id' => 'B-9123-KDA',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'speed_kmh' => 95.5, // Overspeed (> 90 km/h)
            'fuel_level_pct' => 78.0,
            'reefer_temp_celsius' => -10.0, // Melebihi ambang batas -15 C
            'is_door_open' => false,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertEquals('B-9123-KDA', $data['log']['vehicle_id']);
        $this->assertCount(2, $data['alerts']); // 1 overspeed, 1 reefer breach

        // 2. Cek Live Fleet Status
        $liveResponse = $this->getJson('/api/v1/telematics/live');

        $liveResponse->assertStatus(200);
        $liveData = $liveResponse->json('data');

        $this->assertNotEmpty($liveData);
        $this->assertEquals('EXCEPTION', $liveData[0]['status']);
        $this->assertCount(2, $liveData[0]['active_alerts']);

        // 3. Resolve salah satu alert
        $alertId = $liveData[0]['active_alerts'][0]['id'];
        $resolveResponse = $this->postJson("/api/v1/telematics/alerts/{$alertId}/resolve");

        $resolveResponse->assertStatus(200);
    }
}
