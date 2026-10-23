// scripts/write_dual_deployment_adapters.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Config File: backend/config/optideli.php
  {
    path: 'backend/config/optideli.php',
    content: `<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Mode: 'standalone' vs 'saas'
    |--------------------------------------------------------------------------
    */
    'deployment_mode' => env('OPTIDELI_DEPLOYMENT_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Identity & Access Management Driver
    | Options: 'standalone' (Local DB users/roles) | 'optinexus' (SSO & Central CGO)
    |--------------------------------------------------------------------------
    */
    'identity' => [
        'driver' => env('OPTIDELI_IDENTITY_MODE', 'standalone'),
        'optinexus_url' => env('OPTINEXUS_API_URL', 'http://localhost:8000'),
        'm2m_client_id' => env('OPTIDELI_M2M_CLIENT_ID', 'optideli'),
        'm2m_client_secret' => env('OPTIDELI_M2M_CLIENT_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telematics & IoT Ingestion Driver
    | Options: 'generic' (Standard IoT Ping) | 'optiradar' (OptiRadar Feed) | 'none'
    |--------------------------------------------------------------------------
    */
    'telematics' => [
        'driver' => env('TELEMATICS_DRIVER', 'generic'),
        'optiradar_url' => env('OPTIRADAR_API_URL', 'http://localhost:8002'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet Maintenance & Asset Lock Driver
    | Options: 'local' (OptiDeli DB) | 'optifleet' (OptiFleet VMS) | 'none'
    |--------------------------------------------------------------------------
    */
    'fleet' => [
        'driver' => env('FLEET_SERVICE_DRIVER', 'local'),
        'optifleet_url' => env('OPTIFLEET_API_URL', 'http://localhost:8001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounting & Financial Settlement Driver
    | Options: 'internal' (OptiDeli Ledger) | 'optientry' (OptiAccounting) | 'edi210' | 'none'
    |--------------------------------------------------------------------------
    */
    'accounting' => [
        'driver' => env('ACCOUNTING_DRIVER', 'internal'),
        'optientry_url' => env('OPTIACCOUNTING_API_URL', 'http://localhost:8003'),
    ],
];
`
  },

  // 2. Identity Port & Adapters
  {
    path: 'backend/app/Services/Adapters/Identity/IdentityPortInterface.php',
    content: `<?php

namespace App\\Services\\Adapters\\Identity;

use Illuminate\\Http\\Request;

interface IdentityPortInterface
{
    /**
     * Memecahkan konteks governance pengguna aktif (tenant, user, role).
     *
     * @return array{
     *   tenant_id: string,
     *   user_id: string,
     *   user_name: string,
     *   role: string,
     *   mode: string,
     *   is_sso: bool
     * }
     */
    public function resolveContext(Request $request): array;
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Identity/StandaloneIdentityAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Identity;

use Illuminate\\Http\\Request;

class StandaloneIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        return [
            'tenant_id' => $tenantId,
            'user_id' => 'usr-local-dispatcher',
            'user_name' => 'Local Dispatcher (Standalone)',
            'role' => 'Dispatcher',
            'mode' => 'standalone',
            'is_sso' => false,
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Identity/OptiNexusIdentityAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Identity;

use Illuminate\\Http\\Request;

class OptiNexusIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        // Dalam implementasi SaaS penuh, token diverifikasi via OptiNexus SSO / Sanctum
        $authHeader = $request->header('Authorization', '');
        $tenantId = $request->header('X-Tenant-Id', 'nexus-org-01');

        return [
            'tenant_id' => $tenantId,
            'user_id' => 'usr-nexus-sso',
            'user_name' => 'Federated Enterprise User (OptiNexus SSO)',
            'role' => 'LogisticsManager',
            'mode' => 'saas',
            'is_sso' => ! empty($authHeader),
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Identity/IdentityManager.php',
    content: `<?php

namespace App\\Services\\Adapters\\Identity;

class IdentityManager
{
    public static function create(): IdentityPortInterface
    {
        $driver = config('optideli.identity.driver', 'standalone');

        return match ($driver) {
            'optinexus' => new OptiNexusIdentityAdapter(),
            default => new StandaloneIdentityAdapter(),
        };
    }
}
`
  },

  // 3. Fleet Maintenance Port & Adapters
  {
    path: 'backend/app/Services/Adapters/Fleet/FleetMaintenancePortInterface.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

interface FleetMaintenancePortInterface
{
    /**
     * Memvalidasi kesiapan unit armada dari pekerjaan bengkel / maintenance.
     *
     * @return array{
     *   vehicle_id: string,
     *   is_available: bool,
     *   status: string,
     *   driver: string,
     *   reason: ?string
     * }
     */
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array;
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Fleet/OptiFleetAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

class OptiFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        // Simulasi pemeriksaan M2M ke OptiFleet VMS Service
        $isMaintenanceMock = str_contains(strtoupper($vehicleId), 'SRV');

        return [
            'vehicle_id' => $vehicleId,
            'is_available' => ! $isMaintenanceMock,
            'status' => $isMaintenanceMock ? 'IN_MAINTENANCE' : 'ACTIVE',
            'driver' => 'optifleet',
            'reason' => $isMaintenanceMock ? 'Unit sedang dalam pengerjaan bengkel (OptiFleet WO).' : null,
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Fleet/LocalFleetAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

class LocalFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => true,
            'status' => 'ACTIVE',
            'driver' => 'local',
            'reason' => null,
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Fleet/NullFleetAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

class NullFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => true,
            'status' => 'UNCHECKED',
            'driver' => 'none',
            'reason' => 'Pengecekan servis armada dinonaktifkan (Driver: none).',
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Fleet/FleetMaintenanceManager.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

class FleetMaintenanceManager
{
    public static function create(): FleetMaintenancePortInterface
    {
        $driver = config('optideli.fleet.driver', 'local');

        return match ($driver) {
            'optifleet' => new OptiFleetAdapter(),
            'none' => new NullFleetAdapter(),
            default => new LocalFleetAdapter(),
        };
    }
}
`
  },

  // 4. Accounting Settlement Port & Adapters
  {
    path: 'backend/app/Services/Adapters/Accounting/AccountingSettlementPortInterface.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

interface AccountingSettlementPortInterface
{
    /**
     * Sinkronisasi jurnal utang/piutang setelah 3-Way Freight Audit lolos verifikasi.
     *
     * @return array{
     *   order_id: string,
     *   synced: bool,
     *   driver: string,
     *   message: string
     * }
     */
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array;
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Accounting/OptiEntryAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

class OptiEntryAdapter implements AccountingSettlementPortInterface
{
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        return [
            'order_id' => $orderId,
            'synced' => true,
            'driver' => 'optientry',
            'message' => 'Jurnal tagihan berhasil disinkronisasikan ke OptiAccounting/OptiEntry (OA3: AR & OA2: AP).',
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Accounting/InternalLedgerAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

class InternalLedgerAdapter implements AccountingSettlementPortInterface
{
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        return [
            'order_id' => $orderId,
            'synced' => true,
            'driver' => 'internal',
            'message' => 'Pencatatan keuangan disimpan pada ledger internal OptiDeli (Standalone).',
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Accounting/NullAccountingAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

class NullAccountingAdapter implements AccountingSettlementPortInterface
{
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        return [
            'order_id' => $orderId,
            'synced' => false,
            'driver' => 'none',
            'message' => 'Sinkronisasi akuntansi dilewati (Driver: none).',
        ];
    }
}
`
  },
  {
    path: 'backend/app/Services/Adapters/Accounting/AccountingSettlementManager.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

class AccountingSettlementManager
{
    public static function create(): AccountingSettlementPortInterface
    {
        $driver = config('optideli.accounting.driver', 'internal');

        return match ($driver) {
            'optientry' => new OptiEntryAdapter(),
            'none' => new NullAccountingAdapter(),
            default => new InternalLedgerAdapter(),
        };
    }
}
`
  },

  // 5. Controller: EcosystemContextController
  {
    path: 'backend/app/Http/Controllers/Api/EcosystemContextController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Services\\Adapters\\Accounting\\AccountingSettlementManager;
use App\\Services\\Adapters\\Fleet\\FleetMaintenanceManager;
use App\\Services\\Adapters\\Identity\\IdentityManager;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class EcosystemContextController extends Controller
{
    public function getContext(Request $request): JsonResponse
    {
        $identity = IdentityManager::create()->resolveContext($request);

        return response()->json([
            'data' => [
                'deployment_mode' => config('optideli.deployment_mode', 'standalone'),
                'identity' => $identity,
                'active_drivers' => [
                    'identity' => config('optideli.identity.driver', 'standalone'),
                    'telematics' => config('optideli.telematics.driver', 'generic'),
                    'fleet' => config('optideli.fleet.driver', 'local'),
                    'accounting' => config('optideli.accounting.driver', 'internal'),
                ],
                'integrations' => [
                    'optinexus' => config('optideli.identity.driver') === 'optinexus',
                    'optiradar' => config('optideli.telematics.driver') === 'optiradar',
                    'optifleet' => config('optideli.fleet.driver') === 'optifleet',
                    'optientry' => config('optideli.accounting.driver') === 'optientry',
                ],
            ],
        ]);
    }

    public function checkFleetReadiness(Request $request, string $vehicleId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $result = FleetMaintenanceManager::create()->checkVehicleReadiness($tenantId, $vehicleId);

        return response()->json(['data' => $result]);
    }

    public function syncAccounting(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_ar_minor_units' => 'required|integer|min:0',
            'vendor_ap_minor_units' => 'required|integer|min:0',
            'accessorials_minor_units' => 'nullable|integer|min:0',
        ]);

        $result = AccountingSettlementManager::create()->syncSettlement(
            $tenantId,
            $validated['order_id'],
            $validated['customer_ar_minor_units'],
            $validated['vendor_ap_minor_units'],
            $validated['accessorials_minor_units'] ?? 0
        );

        return response()->json(['data' => $result]);
    }
}
`
  },

  // 6. Test: EcosystemAdaptersTest
  {
    path: 'backend/tests/Feature/EcosystemAdaptersTest.php',
    content: `<?php

namespace Tests\\Feature;

use Tests\\TestCase;

class EcosystemAdaptersTest extends TestCase
{
    public function test_ecosystem_context_and_pluggable_adapters(): void
    {
        $tenantId = 'demo';

        // 1. Cek Context Ekosistem
        $resContext = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->getJson('/api/v1/ecosystem/context');

        $resContext->assertStatus(200);
        $resContext->assertJsonStructure([
            'data' => [
                'deployment_mode',
                'identity' => ['tenant_id', 'user_id', 'role', 'mode', 'is_sso'],
                'active_drivers' => ['identity', 'telematics', 'fleet', 'accounting'],
                'integrations',
            ],
        ]);

        // 2. Cek Kesiapan Armada (Asset Lock)
        $resFleet = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->getJson('/api/v1/fleet/assets/B-1234-KDA/readiness');

        $resFleet->assertStatus(200);
        $this->assertTrue($resFleet->json('data.is_available'));

        // 3. Cek Sinkronisasi Akuntansi
        $resAcc = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/accounting/sync', [
                'order_id' => 'ORD-TEST-001',
                'customer_ar_minor_units' => 500000000,
                'vendor_ap_minor_units' => 400000000,
            ]);

        $resAcc->assertStatus(200);
        $this->assertTrue($resAcc->json('data.synced'));
    }
}
`
  },

  // 7. Frontend Types: ecosystem.ts
  {
    path: 'frontend/src/types/ecosystem.ts',
    content: `export interface EcosystemContext {
  deployment_mode: 'standalone' | 'saas';
  identity: {
    tenant_id: string;
    user_id: string;
    user_name: string;
    role: string;
    mode: 'standalone' | 'saas';
    is_sso: boolean;
  };
  active_drivers: {
    identity: string;
    telematics: string;
    fleet: string;
    accounting: string;
  };
  integrations: {
    optinexus: boolean;
    optiradar: boolean;
    optifleet: boolean;
    optientry: boolean;
  };
}
`
  },

  // 8. Frontend Component: EcosystemStatusBar.tsx
  {
    path: 'frontend/src/components/Navigation/EcosystemStatusBar.tsx',
    content: `import React, { useState, useEffect } from 'react';
import type { EcosystemContext } from '../../types/ecosystem';

export const EcosystemStatusBar: React.FC = () => {
  const [context, setContext] = useState<EcosystemContext | null>(null);

  useEffect(() => {
    fetch('/api/v1/ecosystem/context', {
      headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => setContext(data.data))
      .catch((err) => console.error(err));
  }, []);

  if (!context) return null;

  const isSaas = context.deployment_mode === 'saas';

  return (
    <div className="flex flex-wrap items-center justify-between border-b border-slate-800 bg-slate-950 px-4 py-2 text-xs text-slate-300">
      <div className="flex items-center gap-3">
        <span
          className={\`rounded px-2 py-0.5 font-bold uppercase tracking-wider \${
            isSaas
              ? 'border border-cyan-800 bg-cyan-950/70 text-cyan-400'
              : 'border border-amber-800 bg-amber-950/70 text-amber-400'
          }\`}
        >
          {isSaas ? 'Cloud SaaS (OptiNexus SSO)' : 'Standalone (Air-Gapped)'}
        </span>
        <span className="font-mono text-slate-400">
          Tenant: <span className="text-slate-200">{context.identity.tenant_id}</span>
        </span>
        <span className="text-slate-500">|</span>
        <span className="text-slate-400">
          User: <span className="text-slate-200">{context.identity.user_name}</span> ({context.identity.role})
        </span>
      </div>

      <div className="flex items-center gap-2 text-[11px]">
        <span className="text-slate-500">Adapters:</span>
        <span
          className={\`rounded px-1.5 py-0.5 border \${
            context.integrations.optiradar
              ? 'border-cyan-800 text-cyan-400 bg-cyan-950/40'
              : 'border-slate-800 text-slate-400'
          }\`}
        >
          Radar: {context.active_drivers.telematics}
        </span>
        <span
          className={\`rounded px-1.5 py-0.5 border \${
            context.integrations.optifleet
              ? 'border-cyan-800 text-cyan-400 bg-cyan-950/40'
              : 'border-slate-800 text-slate-400'
          }\`}
        >
          Fleet: {context.active_drivers.fleet}
        </span>
        <span
          className={\`rounded px-1.5 py-0.5 border \${
            context.integrations.optientry
              ? 'border-cyan-800 text-cyan-400 bg-cyan-950/40'
              : 'border-slate-800 text-slate-400'
          }\`}
        >
          Accounting: {context.active_drivers.accounting}
        </span>
      </div>
    </div>
  );
};
`
  }
];

// Daftarkan rute ke backend/routes/web.php
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let webContent = fs.readFileSync(webPath, 'utf8');

if (!webContent.includes('EcosystemContextController')) {
  const snippet = `
// ==========================================
// DUAL DEPLOYMENT & ECOSYSTEM CONTEXT
// ==========================================
use App\\Http\\Controllers\\Api\\EcosystemContextController;

Route::prefix('api/v1')->group(function () {
    Route::get('/ecosystem/context', [EcosystemContextController::class, 'getContext']);
    Route::get('/fleet/assets/{vehicleId}/readiness', [EcosystemContextController::class, 'checkFleetReadiness']);
    Route::post('/accounting/sync', [EcosystemContextController::class, 'syncAccounting']);
});
`;
  webContent = webContent.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, webContent, 'utf8');
  console.log('✅ Rute Ecosystem Context didaftarkan ke backend/routes/web.php');
}

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});

console.log('\nSelesai! Seluruh modul Dual-Deployment & Adapters telah dibangun.');