// scripts/fix_edi_204_parser.js
const fs = require('fs');
const path = require('path');

const servicePath = path.resolve(process.cwd(), 'backend/app/Services/EDI/Edi204Service.php');
const content = `<?php

namespace App\\Services\\EDI;

use App\\Models\\EdiTransmission;
use Carbon\\Carbon;
use InvalidArgumentException;

class Edi204Service
{
    /**
     * Parsing dokumen ANSI X12 204 (Motor Carrier Load Tender).
     *
     * @return array<string, mixed>
     */
    public function parseX12(string $tenantId, string $rawX12): array
    {
        if (empty(trim($rawX12))) {
            throw new InvalidArgumentException('Payload EDI 204 tidak boleh kosong.');
        }

        $segments = array_map('trim', explode('~', $rawX12));
        $parsed = [
            'shipment_id' => null,
            'origin_zone' => 'JKT-HUB',
            'destination_zone' => 'SBY-HUB',
            'cargo_description' => 'General Merchandise',
            'total_weight_kg' => 1000.0,
            'sell_price_minor_units' => 450000000,
        ];

        foreach ($segments as $seg) {
            $elements = explode('*', $seg);
            $tag = $elements[0] ?? '';

            if ($tag === 'B2') {
                $shipmentCandidate = ! empty($elements[3]) ? $elements[3] : (! empty($elements[4]) ? $elements[4] : null);
                if ($shipmentCandidate !== null) {
                    $parsed['shipment_id'] = $shipmentCandidate;
                }
            } elseif ($tag === 'N1') {
                $entity = $elements[1] ?? '';
                if ($entity === 'SH') {
                    $parsed['origin_zone'] = $elements[2] ?? $parsed['origin_zone'];
                } elseif ($entity === 'CN') {
                    $parsed['destination_zone'] = $elements[2] ?? $parsed['destination_zone'];
                }
            } elseif ($tag === 'OID') {
                $parsed['cargo_description'] = $elements[1] ?? $parsed['cargo_description'];
                if (isset($elements[4])) {
                    $parsed['total_weight_kg'] = (float) $elements[4];
                }
            } elseif ($tag === 'L3') {
                if (isset($elements[3])) {
                    $parsed['sell_price_minor_units'] = (int) $elements[3];
                }
            }
        }

        if (empty($parsed['shipment_id'])) {
            $parsed['shipment_id'] = sprintf('SHP-%s', strtoupper(bin2hex(random_bytes(4))));
        }

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '204',
            'direction' => 'INBOUND',
            'reference_id' => $parsed['shipment_id'],
            'raw_content' => $rawX12,
            'parsed_payload' => $parsed,
            'status' => 'PROCESSED',
            'transmitted_at' => Carbon::now(),
        ]);

        return $parsed;
    }
}
`;

fs.writeFileSync(servicePath, content, 'utf8');
console.log('✅ Diperbaiki: backend/app/Services/EDI/Edi204Service.php');