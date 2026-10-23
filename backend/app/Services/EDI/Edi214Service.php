<?php

namespace App\Services\EDI;

use App\Models\EdiTransmission;
use Carbon\Carbon;

class Edi214Service
{
    /**
     * Memetakan status internal OptiDeli ke kode standar ANSI X12 AT7.
     */
    protected const STATUS_MAP = [
        'DISPATCHED' => 'AF',
        'EN_ROUTE' => 'X6',
        'ARRIVED' => 'X1',
        'DELIVERED' => 'D1',
    ];

    /**
     * Mengonstruksi pesan status ANSI X12 214 Outbound.
     */
    public function generateX12(
        string $tenantId,
        string $shipmentId,
        string $internalStatus,
        string $locationCity,
        ?string $etaIso = null
    ): string {
        $ediCode = self::STATUS_MAP[strtoupper($internalStatus)] ?? 'X6';
        $now = Carbon::now();
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('Hi');

        $x12 = sprintf(
            "ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*CUSTOMERERP    *%s*%s*U*00401*000000001*0*P*>~
" .
            "GS*QM*OPTIDELI*CUSTOMERERP*%s*%s*1*X*004010~
" .
            "ST*214*0001~
" .
            "B10*%s*%s*OPTD~
" .
            "LX*1~
" .
            "AT7*%s*NS***%s*%s*LT~
" .
            "MS1*%s*ID*ID~
" .
            "SE*7*0001~
" .
            "GE*1*1~
" .
            "IEA*1*000000001~",
            $dateStr,
            $timeStr,
            $dateStr,
            $timeStr,
            $shipmentId,
            $shipmentId,
            $ediCode,
            $dateStr,
            $timeStr,
            strtoupper($locationCity)
        );

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '214',
            'direction' => 'OUTBOUND',
            'reference_id' => $shipmentId,
            'raw_content' => $x12,
            'parsed_payload' => [
                'shipment_id' => $shipmentId,
                'status_code' => $ediCode,
                'internal_status' => $internalStatus,
                'location' => $locationCity,
                'eta' => $etaIso,
            ],
            'status' => 'TRANSMITTED',
            'transmitted_at' => $now,
        ]);

        return $x12;
    }
}
