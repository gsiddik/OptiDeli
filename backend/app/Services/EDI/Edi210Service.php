<?php

namespace App\Services\EDI;

use App\Models\EdiTransmission;
use Carbon\Carbon;

class Edi210Service
{
    /**
     * Membentuk dokumen tagihan ANSI X12 210 untuk sinkronisasi piutang/utang ke OptiEntry / ERP Finance.
     */
    public function generateX12(
        string $tenantId,
        string $invoiceNumber,
        string $orderId,
        int $customerArMinorUnits,
        int $vendorApMinorUnits,
        int $accessorialsMinorUnits = 0
    ): string {
        $now = Carbon::now();
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('Hi');
        $totalAmountMinor = $customerArMinorUnits + $accessorialsMinorUnits;

        $segments = [
            sprintf('ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*FINANCEERP     *%s*%s*U*00401*000000001*0*P*>', $dateStr, $timeStr),
            sprintf('GS*IM*OPTIDELI*FINANCEERP*%s*%s*1*X*004010', $dateStr, $timeStr),
            'ST*210*0001',
            sprintf('B3*B*%s*%s*PP*%s*%d**IDR', $invoiceNumber, $orderId, $dateStr, $totalAmountMinor),
            'N1*BT*CUSTOMER-ACCOUNTS-PAYABLE',
            'N1*CA*OPTIDELI-OPERATIONS',
            sprintf('L3*0*G*%d', $totalAmountMinor),
            'SE*7*0001',
            'GE*1*1',
            'IEA*1*000000001~',
        ];

        $x12 = implode("~\n", $segments);

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '210',
            'direction' => 'OUTBOUND',
            'reference_id' => $invoiceNumber,
            'raw_content' => $x12,
            'parsed_payload' => [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'customer_ar_minor_units' => $customerArMinorUnits,
                'vendor_ap_minor_units' => $vendorApMinorUnits,
                'accessorials_minor_units' => $accessorialsMinorUnits,
                'total_minor_units' => $totalAmountMinor,
            ],
            'status' => 'TRANSMITTED',
            'transmitted_at' => $now,
        ]);

        return $x12;
    }
}
