// scripts/fix_code_style.js
const fs = require('fs');
const path = require('path');

const phpFiles = [
  {
    path: 'backend/app/Services/Tendering/RateCardEngineService.php',
    content: `<?php

namespace App\\Services\\Tendering;

use App\\Models\\VendorRateCard;
use InvalidArgumentException;

class RateCardEngineService
{
    public function calculateEstimatedCost(
        string $tenantId,
        string $vendorId,
        string $vehicleType,
        string $zoneOrigin,
        string $zoneDestination,
        float $actualDistanceKm
    ): int {
        $rateCard = VendorRateCard::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('vehicle_type', $vehicleType)
            ->where('zone_origin', $zoneOrigin)
            ->where('zone_destination', $zoneDestination)
            ->where('is_active', true)
            ->first();

        if (! $rateCard) {
            throw new InvalidArgumentException("Tarif Rate Card tidak ditemukan untuk vendor '{$vendorId}' pada rute {$zoneOrigin} -> {$zoneDestination}");
        }

        $baseRate = $rateCard->base_rate_minor_units;
        $billableDistanceKm = max(0.0, $actualDistanceKm - $rateCard->min_distance_km);
        $distanceSurcharge = (int) round($billableDistanceKm * $rateCard->rate_per_km_minor_units);

        return $baseRate + $distanceSurcharge;
    }
}
`
  },
  {
    path: 'backend/app/Services/Tendering/WaterfallTenderEngineService.php',
    content: `<?php

namespace App\\Services\\Tendering;

use App\\Models\\TenderDispatch;
use App\\Models\\TenderOffer;
use Carbon\\Carbon;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

class WaterfallTenderEngineService
{
    public function __construct(
        protected RateCardEngineService $rateCardEngine
    ) {}

    public function initiateWaterfall(
        string $tenantId,
        string $orderId,
        array $rankedVendors,
        int $timeoutMinutes = 30
    ): TenderDispatch {
        if (empty($rankedVendors)) {
            throw new RuntimeException('Daftar peringkat vendor tidak boleh kosong untuk memicu waterfall tender.');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $rankedVendors, $timeoutMinutes) {
            $dispatch = TenderDispatch::create([
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'tendering_mode' => 'WATERFALL',
                'status' => 'RUNNING',
                'current_sequence' => 1,
                'timeout_minutes_per_vendor' => $timeoutMinutes,
                'started_at' => Carbon::now(),
            ]);

            $sequence = 1;
            foreach ($rankedVendors as $v) {
                $costMinorUnits = $this->rateCardEngine->calculateEstimatedCost(
                    $tenantId,
                    $v['vendor_id'],
                    $v['vehicle_type'],
                    $v['zone_origin'],
                    $v['zone_destination'],
                    $v['distance_km']
                );

                TenderOffer::create([
                    'tenant_id' => $tenantId,
                    'tender_dispatch_id' => $dispatch->id,
                    'vendor_id' => $v['vendor_id'],
                    'sequence' => $sequence,
                    'offered_cost_minor_units' => $costMinorUnits,
                    'status' => $sequence === 1 ? 'OFFERED' : 'PENDING',
                    'offered_at' => $sequence === 1 ? Carbon::now() : null,
                    'expires_at' => $sequence === 1 ? Carbon::now()->addMinutes($timeoutMinutes) : null,
                ]);

                $sequence++;
            }

            return $dispatch->load('offers');
        });
    }

    public function processVendorResponse(
        string $tenantId,
        string $offerId,
        string $action,
        ?string $reason = null
    ): TenderDispatch {
        return DB::transaction(function () use ($tenantId, $offerId, $action, $reason) {
            $offer = TenderOffer::where('tenant_id', $tenantId)
                ->where('id', $offerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($offer->status !== 'OFFERED') {
                throw new RuntimeException("Tawaran ini tidak lagi berstatus aktif (status: {$offer->status}).");
            }

            $dispatch = TenderDispatch::where('tenant_id', $tenantId)
                ->where('id', $offer->tender_dispatch_id)
                ->lockForUpdate()
                ->firstOrFail();

            $now = Carbon::now();

            if ($action === 'ACCEPT') {
                $offer->update([
                    'status' => 'ACCEPTED',
                    'responded_at' => $now,
                ]);

                TenderOffer::where('tender_dispatch_id', $dispatch->id)
                    ->where('sequence', '>', $offer->sequence)
                    ->update(['status' => 'BYPASSED']);

                $dispatch->update([
                    'status' => 'ACCEPTED',
                    'accepted_vendor_id' => $offer->vendor_id,
                    'winning_cost_minor_units' => $offer->offered_cost_minor_units,
                    'completed_at' => $now,
                ]);
            } else {
                $offer->update([
                    'status' => 'REJECTED',
                    'responded_at' => $now,
                    'rejection_reason' => $reason ?? 'Vendor menolak penugasan.',
                ]);

                $this->escalateNextVendor($dispatch, $offer->sequence + 1);
            }

            return $dispatch->fresh(['offers']);
        });
    }

    protected function escalateNextVendor(TenderDispatch $dispatch, int $nextSequence): void
    {
        $nextOffer = TenderOffer::where('tender_dispatch_id', $dispatch->id)
            ->where('sequence', $nextSequence)
            ->lockForUpdate()
            ->first();

        if ($nextOffer) {
            $now = Carbon::now();
            $nextOffer->update([
                'status' => 'OFFERED',
                'offered_at' => $now,
                'expires_at' => $now->copy()->addMinutes($dispatch->timeout_minutes_per_vendor),
            ]);

            $dispatch->update(['current_sequence' => $nextSequence]);
        } else {
            $dispatch->update([
                'status' => 'EXHAUSTED',
                'completed_at' => Carbon::now(),
            ]);
        }
    }
}
`
  },
  {
    path: 'backend/database/migrations/2026_10_23_000001_create_vendor_rate_cards_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('vendor_id')->index();
            $table->string('vehicle_type');
            $table->string('zone_origin');
            $table->string('zone_destination');
            $table->bigInteger('base_rate_minor_units');
            $table->bigInteger('rate_per_km_minor_units')->default(0);
            $table->integer('min_distance_km')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vendor_id', 'is_active']);
            $table->unique(['tenant_id', 'vendor_id', 'vehicle_type', 'zone_origin', 'zone_destination'], 'uq_rate_card_matrix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_rate_cards');
    }
};
`
  },
  {
    path: 'backend/database/migrations/2026_10_23_000002_create_tender_dispatches_and_offers_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->uuid('order_id')->index();
            $table->string('tendering_mode')->default('WATERFALL');
            $table->string('status')->default('RUNNING');
            $table->integer('current_sequence')->default(1);
            $table->integer('timeout_minutes_per_vendor')->default(30);
            $table->string('accepted_vendor_id')->nullable();
            $table->bigInteger('winning_cost_minor_units')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('tender_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->uuid('tender_dispatch_id')->index();
            $table->string('vendor_id')->index();
            $table->integer('sequence')->default(1);
            $table->bigInteger('offered_cost_minor_units');
            $table->string('status')->default('PENDING');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('tender_dispatch_id')->references('id')->on('tender_dispatches')->onDelete('cascade');
            $table->index(['tenant_id', 'vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_offers');
        Schema::dropIfExists('tender_dispatches');
    }
};
`
  },
  {
    path: 'backend/tests/Feature/WaterfallTenderTest.php',
    content: `<?php

namespace Tests\\Feature;

use App\\Models\\VendorRateCard;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class WaterfallTenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_card_minor_unit_calculation_and_waterfall_cascading(): void
    {
        $tenantId = 'demo';
        $orderId = (string) Str::uuid();

        // 1. Setup Rate Cards (1 unit = Rp 0,01)
        VendorRateCard::create([
            'tenant_id' => $tenantId,
            'vendor_id' => 'VND-ALPHA',
            'vehicle_type' => 'CDD',
            'zone_origin' => 'JKT-HUB',
            'zone_destination' => 'BDG-HUB',
            'base_rate_minor_units' => 350000000,
            'rate_per_km_minor_units' => 450000,
            'min_distance_km' => 10,
            'is_active' => true,
        ]);

        VendorRateCard::create([
            'tenant_id' => $tenantId,
            'vendor_id' => 'VND-BETA',
            'vehicle_type' => 'CDD',
            'zone_origin' => 'JKT-HUB',
            'zone_destination' => 'BDG-HUB',
            'base_rate_minor_units' => 380000000,
            'rate_per_km_minor_units' => 400000,
            'min_distance_km' => 10,
            'is_active' => true,
        ]);

        // 2. Start Waterfall Tender via API
        $response = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/tenders/waterfall/start', [
                'order_id' => $orderId,
                'timeout_minutes' => 15,
                'ranked_vendors' => [
                    [
                        'vendor_id' => 'VND-ALPHA',
                        'vehicle_type' => 'CDD',
                        'zone_origin' => 'JKT-HUB',
                        'zone_destination' => 'BDG-HUB',
                        'distance_km' => 150.0,
                    ],
                    [
                        'vendor_id' => 'VND-BETA',
                        'vehicle_type' => 'CDD',
                        'zone_origin' => 'JKT-HUB',
                        'zone_destination' => 'BDG-HUB',
                        'distance_km' => 150.0,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('RUNNING', $data['status']);
        $this->assertCount(2, $data['offers']);
        $this->assertEquals('OFFERED', $data['offers'][0]['status']);
        $this->assertEquals('PENDING', $data['offers'][1]['status']);

        $offerAlphaId = $data['offers'][0]['id'];
        $offerBetaId = $data['offers'][1]['id'];

        // 3. Vendor ALPHA menolak -> cascade otomatis ke Vendor BETA
        $rejectResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson("/api/v1/tenders/offers/{$offerAlphaId}/respond", [
                'action' => 'REJECT',
                'rejection_reason' => 'Unit sedang perawatan berkala.',
            ]);

        $rejectResponse->assertStatus(200);
        $offersUpdated = $rejectResponse->json('data.offers');
        $this->assertEquals('REJECTED', $offersUpdated[0]['status']);
        $this->assertEquals('OFFERED', $offersUpdated[1]['status']);

        // 4. Vendor BETA menerima -> tender berstatus ACCEPTED
        $acceptResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson("/api/v1/tenders/offers/{$offerBetaId}/respond", [
                'action' => 'ACCEPT',
            ]);

        $acceptResponse->assertStatus(200);
        $this->assertEquals('ACCEPTED', $acceptResponse->json('data.status'));
        $this->assertEquals('VND-BETA', $acceptResponse->json('data.accepted_vendor_id'));
    }
}
`
  }
];

phpFiles.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Pint Formatted: ${f.path}`);
});