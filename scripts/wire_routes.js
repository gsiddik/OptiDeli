// scripts/wire_routes.js
const fs = require('fs');
const path = require('path');

const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
let content = fs.readFileSync(appPath, 'utf8');

console.log('1. Memasang imports komponen enterprise ke App.tsx...');
const imports = `import { EcosystemStatusBar } from './components/Navigation/EcosystemStatusBar';
import { LoadOptimizationViewer3D } from './components/Optimizer/LoadOptimizationViewer3D';
import { RateCardDynamicGrid } from './components/RateCards/RateCardDynamicGrid';
import { TenderWaterfallBoard } from './components/Tendering/TenderWaterfallBoard';
import { GISControlTowerMap } from './components/Telematics/GISControlTowerMap';
import { EsgCarbonDashboard } from './components/Analytics/EsgCarbonDashboard';
import { EdiGatewayConsole } from './components/EDI/EdiGatewayConsole';
`;

if (!content.includes('EcosystemStatusBar')) {
  content = imports + content;
}

console.log('2. Mendaftarkan menu ke grup ENTERPRISE di array navigation...');
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

if (!content.includes('/load-optimizer')) {
  // Ganti penutup array navigation
  content = content.replace(/\n\];\s*(?=function\s+Shell)/, '\n' + enterpriseNavGroup + '\n');
}

console.log('3. Mendaftarkan child routes resmi ke React Router...');
const newRoutes = `          <Route path="load-optimizer" element={<LoadOptimizationViewer3D />} />
          <Route path="tendering" element={<div className="space-y-6"><TenderWaterfallBoard /><RateCardDynamicGrid /></div>} />
          <Route path="control-tower" element={<GISControlTowerMap />} />
          <Route path="esg-carbon" element={<EsgCarbonDashboard />} />
          <Route path="edi-gateway" element={<EdiGatewayConsole />} />
          <Route path="policy"`;

if (!content.includes('path="load-optimizer"')) {
  content = content.replace('<Route path="policy"', newRoutes);
}

console.log('4. Memasang EcosystemStatusBar di dalam Shell layout...');
if (!content.includes('<EcosystemStatusBar />')) {
  content = content.replace('<Outlet />', '<EcosystemStatusBar />\n              <Outlet />');
}

fs.writeFileSync(appPath, content, 'utf8');
console.log('✅ Berhasil menyambungkan seluruh rute enterprise ke App.tsx!');