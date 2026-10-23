// scripts/fix_manager_pint.js
const fs = require('fs');
const path = require('path');

// Cek apakah ada pint.json untuk melihat preset
const pintJsonPath = path.resolve(process.cwd(), 'backend/pint.json');
if (fs.existsSync(pintJsonPath)) {
  console.log('pint.json:', fs.readFileSync(pintJsonPath, 'utf8'));
}

const files = [
  // 1. IdentityManager.php
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
            'optinexus' => new OptiNexusIdentityAdapter,
            default => new StandaloneIdentityAdapter,
        };
    }
}
`
  },

  // 2. FleetMaintenanceManager.php
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
            'optifleet' => new OptiFleetAdapter,
            'none' => new NullFleetAdapter,
            default => new LocalFleetAdapter,
        };
    }
}
`
  },

  // 3. AccountingSettlementManager.php
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
            'optientry' => new OptiEntryAdapter,
            'none' => new NullAccountingAdapter,
            default => new InternalLedgerAdapter,
        };
    }
}
`
  }
];

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Diperbaiki: ${f.path}`);
});