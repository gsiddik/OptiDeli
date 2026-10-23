// scripts/write_increment_2b.js
const fs = require('fs');
const path = require('path');

const files = [
  {
    path: 'backend/database/migrations/2026_10_23_000001_create_vendor_rate_cards_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
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

return new class extends Migration {
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
    path: 'backend/app/Models/VendorRateCard.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class VendorRateCard extends Model
{
    use HasUuids;

    protected $table = 'vendor_rate_cards';

    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'vehicle_type',
        'zone_origin',
        'zone_destination',
        'base_rate_minor_units',
        'rate_per_km_minor_units',
        'min_distance_km',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'base_rate_minor_units' => 'integer',
        'rate_per_km_minor_units' => 'integer',
        'min_distance_km' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
`
  },
  {
    path: 'backend/app/Models/TenderDispatch.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class TenderDispatch extends Model
{
    use HasUuids;

    protected $table = 'tender_dispatches';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'tendering_mode',
        'status',
        'current_sequence',
        'timeout_minutes_per_vendor',
        'accepted_vendor_id',
        'winning_cost_minor_units',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'current_sequence' => 'integer',
        'timeout_minutes_per_vendor' => 'integer',
        'winning_cost_minor_units' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function offers(): HasMany
    {
        return $this->hasMany(TenderOffer::class, 'tender_dispatch_id')->orderBy('sequence');
    }
}
`
  },
  {
    path: 'backend/app/Models/TenderOffer.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class TenderOffer extends Model
{
    use HasUuids;

    protected $table = 'tender_offers';

    protected $fillable = [
        'tenant_id',
        'tender_dispatch_id',
        'vendor_id',
        'sequence',
        'offered_cost_minor_units',
        'status',
        'offered_at',
        'expires_at',
        'responded_at',
        'rejection_reason',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'offered_cost_minor_units' => 'integer',
        'offered_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(TenderDispatch::class, 'tender_dispatch_id');
    }
}
`
  },
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

        if (!$rateCard) {
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
            throw new RuntimeException("Daftar peringkat vendor tidak boleh kosong untuk memicu waterfall tender.");
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
    path: 'frontend/src/types/tendering.ts',
    content: `export interface VendorRateCard {
  id: string;
  tenant_id: string;
  vendor_id: string;
  vehicle_type: string;
  zone_origin: string;
  zone_destination: string;
  base_rate_minor_units: number;
  rate_per_km_minor_units: number;
  min_distance_km: number;
  is_active: boolean;
}

export type TenderOfferStatus = 'PENDING' | 'OFFERED' | 'ACCEPTED' | 'REJECTED' | 'EXPIRED' | 'BYPASSED';

export interface TenderOffer {
  id: string;
  vendor_id: string;
  sequence: number;
  offered_cost_minor_units: number;
  status: TenderOfferStatus;
  offered_at: string | null;
  expires_at: string | null;
  rejection_reason?: string | null;
}

export interface TenderDispatch {
  id: string;
  order_id: string;
  tendering_mode: 'WATERFALL' | 'BROADCAST';
  status: 'RUNNING' | 'ACCEPTED' | 'EXHAUSTED' | 'CANCELLED';
  current_sequence: number;
  timeout_minutes_per_vendor: number;
  accepted_vendor_id: string | null;
  winning_cost_minor_units: number | null;
  started_at: string;
  offers: TenderOffer[];
}

export const formatRupiahFromMinor = (minorUnits: number): string => {
  const rupiah = minorUnits / 100;
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(rupiah);
};
`
  }
];

console.log('Menulis file Increment 2B ke workspace lokal...');
files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});
console.log('\nSelesai! Semua file berhasil dibuat.');