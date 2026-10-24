<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class LoadOptimizerTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_load_optimizer_places_cargo_and_calculates_axle_loads(): void
    {
        $this->signInTenantMember();
        $payload = [
            'vehicle' => [
                'type' => 'CDD Box',
                'max_weight_kg' => 4000,
                'container_dimensions' => [
                    'length_cm' => 420,
                    'width_cm' => 200,
                    'height_cm' => 200,
                ],
                'axle_limits' => [
                    'front_max_kg' => 1800,
                    'rear_max_kg' => 2600,
                ],
            ],
            'cargo_items' => [
                [
                    'id' => 'CARGO-ELEC',
                    'name' => 'Kardus TV Elektronik',
                    'length_cm' => 60,
                    'width_cm' => 40,
                    'height_cm' => 40,
                    'weight_kg' => 20,
                    'quantity' => 10,
                    'is_hazmat' => false,
                    'stackable' => true,
                ],
                [
                    'id' => 'CARGO-HAZMAT',
                    'name' => 'Bahan Kimia Cat (Hazmat)',
                    'length_cm' => 50,
                    'width_cm' => 50,
                    'height_cm' => 50,
                    'weight_kg' => 30,
                    'quantity' => 2,
                    'is_hazmat' => true,
                    'stackable' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/load-optimization/build', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_valid_dispatch', true);
        $response->assertJsonPath('data.summary.total_placed_items', 12);
        $response->assertJsonPath('data.summary.total_unplaced_items', 0);
        $response->assertJsonPath('data.axle_distribution.front_status', 'OK');
        $response->assertJsonPath('data.axle_distribution.rear_status', 'OK');

        // Pastikan item Hazmat ditaruh di lantai dasar (z = 0)
        $placed = $response->json('data.placed_items');
        foreach ($placed as $item) {
            if ($item['is_hazmat']) {
                $this->assertEquals(0, $item['position']['z']);
            }
        }
    }
}
