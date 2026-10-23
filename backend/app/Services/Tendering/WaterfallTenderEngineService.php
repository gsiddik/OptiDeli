<?php

namespace App\Services\Tendering;

use App\Models\TenderDispatch;
use App\Models\TenderOffer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
