// scripts/clean_wire_app.js
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

console.log('1. Mengembalikan App.tsx ke versi asli yang bersih dari git...');
execSync('git checkout HEAD -- frontend/src/App.tsx', { stdio: 'inherit' });

const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
let content = fs.readFileSync(appPath, 'utf8');

console.log('2. Menambahkan import komponen enterprise...');
const imports = `import { LoadOptimizationViewer3D } from './components/Optimizer/LoadOptimizationViewer3D';
import { RateCardDynamicGrid } from './components/RateCards/RateCardDynamicGrid';
import { TenderWaterfallBoard } from './components/Tendering/TenderWaterfallBoard';
import { GISControlTowerMap } from './components/Telematics/GISControlTowerMap';
import { EsgCarbonDashboard } from './components/Analytics/EsgCarbonDashboard';
import { EdiGatewayConsole } from './components/EDI/EdiGatewayConsole';
`;

content = imports + content;

console.log('3. Mendaftarkan grup navigasi ENTERPRISE ke sidebar menu...');
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

fs.writeFileSync(appPath, content, 'utf8');
console.log('✅ App.tsx berhasil diperbarui secara bersih (bebas activeTab)!');