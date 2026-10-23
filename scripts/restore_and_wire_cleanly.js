// scripts/restore_and_wire_cleanly.js
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

console.log('1. Mengambil App.tsx asli yang 100% murni dan hijau dari origin/main...');
const pristineApp = execSync('git show origin/main:frontend/src/App.tsx', { encoding: 'utf8' });

console.log('2. Menambahkan import komponen enterprise...');
const imports = `import { LoadOptimizationViewer3D } from './components/Optimizer/LoadOptimizationViewer3D';
import { RateCardDynamicGrid } from './components/RateCards/RateCardDynamicGrid';
import { TenderWaterfallBoard } from './components/Tendering/TenderWaterfallBoard';
import { GISControlTowerMap } from './components/Telematics/GISControlTowerMap';
import { EsgCarbonDashboard } from './components/Analytics/EsgCarbonDashboard';
import { EdiGatewayConsole } from './components/EDI/EdiGatewayConsole';
`;

let content = imports + pristineApp;

console.log('3. Mendaftarkan menu ke grup ENTERPRISE di array navigation...');
const enterpriseNavGroup = `  {
    group: "ENTERPRISE",
    items: [
      { to: "/load-optimizer", label: "3D Load Optimizer", icon: "orders" },
      { to: "/tendering", label: "Rate & Tendering", icon: "fleet" },
      { to: "/control-tower", label: "GIS Control Tower", icon: "dispatch" },
      { to: "/esg-carbon", label: "Jejak Karbon ESG", icon: "roadmap" },
      { to: "/edi-gateway", label: "EDI Gateway", icon: "audit" },
    ],
  },
];`;

content = content.replace(/\n\];\s*(?=function\s+Shell)/, '\n' + enterpriseNavGroup + '\n');

console.log('4. Mendaftarkan child routes resmi ke React Router...');
const newRoutes = `          <Route path="load-optimizer" element={<LoadOptimizationViewer3D />} />
          <Route
            path="tendering"
            element={
              <div className="space-y-6">
                <TenderWaterfallBoard />
                <RateCardDynamicGrid />
              </div>
            }
          />
          <Route path="control-tower" element={<GISControlTowerMap />} />
          <Route path="esg-carbon" element={<EsgCarbonDashboard />} />
          <Route path="edi-gateway" element={<EdiGatewayConsole />} />
          <Route path="policy"`;

content = content.replace('<Route path="policy"', newRoutes);

const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
fs.writeFileSync(appPath, content, 'utf8');
console.log('✅ App.tsx berhasil dibangun ulang dari origin/main tanpa ada activeTab!');