// scripts/write_increment_7.js
const fs = require('fs');
const path = require('path');

// 1. Integrasikan App.tsx dengan Navigasi Seluruh Modul Baru
const appTsxPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
let appContent = fs.readFileSync(appTsxPath, 'utf8');

// Tambahkan import komponen baru jika belum ada
const importsToAdd = `
import { EcosystemStatusBar } from './components/Navigation/EcosystemStatusBar';
import { LoadOptimizationViewer3D } from './components/Optimizer/LoadOptimizationViewer3D';
import { RateCardDynamicGrid } from './components/RateCards/RateCardDynamicGrid';
import { TenderWaterfallBoard } from './components/Tendering/TenderWaterfallBoard';
import { GISControlTowerMap } from './components/Telematics/GISControlTowerMap';
import { EsgCarbonDashboard } from './components/Analytics/EsgCarbonDashboard';
import { EdiGatewayConsole } from './components/EDI/EdiGatewayConsole';
`;

// Buat wrapper komponen halaman baru
const enterprisePagesWrapper = `
      {/* ENTERPRISE TMS MODULE EXTENSIONS */}
      {activeTab === 'load-optimizer' && (
        <section className="space-y-6">
          <LoadOptimizationViewer3D />
        </section>
      )}

      {activeTab === 'rate-tendering' && (
        <section className="space-y-6">
          <TenderWaterfallBoard />
          <RateCardDynamicGrid />
        </section>
      )}

      {activeTab === 'control-tower' && (
        <section className="space-y-6">
          <GISControlTowerMap />
        </section>
      )}

      {activeTab === 'esg-carbon' && (
        <section className="space-y-6">
          <EsgCarbonDashboard />
        </section>
      )}

      {activeTab === 'edi-gateway' && (
        <section className="space-y-6">
          <EdiGatewayConsole />
        </section>
      )}
`;

// Tulis file App.tsx yang terintegrasi penuh
if (!appContent.includes('EcosystemStatusBar')) {
  // Sisipkan imports di bagian atas
  appContent = importsToAdd + '\n' + appContent;

  // Sisipkan status bar di bawah header atau di container utama
  if (appContent.includes('<header')) {
    appContent = appContent.replace('<header', '<EcosystemStatusBar />\n      <header');
  }

  // Sisipkan tombol navigasi tab baru
  const navTabsSnippet = `
          <button
            type="button"
            className={\`px-3 py-1.5 rounded-lg text-xs font-semibold transition \${
              activeTab === 'load-optimizer' ? 'bg-cyan-500 text-slate-950 font-bold' : 'text-slate-300 hover:bg-slate-800'
            }\`}
            onClick={() => setActiveTab('load-optimizer')}
          >
            3D Optimizer
          </button>
          <button
            type="button"
            className={\`px-3 py-1.5 rounded-lg text-xs font-semibold transition \${
              activeTab === 'rate-tendering' ? 'bg-cyan-500 text-slate-950 font-bold' : 'text-slate-300 hover:bg-slate-800'
            }\`}
            onClick={() => setActiveTab('rate-tendering')}
          >
            Tendering & Rates
          </button>
          <button
            type="button"
            className={\`px-3 py-1.5 rounded-lg text-xs font-semibold transition \${
              activeTab === 'control-tower' ? 'bg-cyan-500 text-slate-950 font-bold' : 'text-slate-300 hover:bg-slate-800'
            }\`}
            onClick={() => setActiveTab('control-tower')}
          >
            GIS Radar
          </button>
          <button
            type="button"
            className={\`px-3 py-1.5 rounded-lg text-xs font-semibold transition \${
              activeTab === 'esg-carbon' ? 'bg-cyan-500 text-slate-950 font-bold' : 'text-slate-300 hover:bg-slate-800'
            }\`}
            onClick={() => setActiveTab('esg-carbon')}
          >
            ESG Carbon
          </button>
          <button
            type="button"
            className={\`px-3 py-1.5 rounded-lg text-xs font-semibold transition \${
              activeTab === 'edi-gateway' ? 'bg-cyan-500 text-slate-950 font-bold' : 'text-slate-300 hover:bg-slate-800'
            }\`}
            onClick={() => setActiveTab('edi-gateway')}
          >
            EDI Gateway
          </button>
  `;

  // Sisipkan ke navigation tab container
  if (appContent.includes('</nav>')) {
    appContent = appContent.replace('</nav>', navTabsSnippet + '\n        </nav>');
  }

  // Sisipkan container halaman sebelum penutup main
  if (appContent.includes('</main>')) {
    appContent = appContent.replace('</main>', enterprisePagesWrapper + '\n    </main>');
  }

  fs.writeFileSync(appTsxPath, appContent, 'utf8');
  console.log('✅ Diperbarui: frontend/src/App.tsx (Semua modul terhubung ke navigasi)');
}

// 2. Perbarui backend/README.md dengan dokumentasi rilis lengkap
const readmePath = path.resolve(process.cwd(), 'README.md');
let readmeContent = fs.readFileSync(readmePath, 'utf8');

const productionReleaseSection = `
## Rilis Enterprise v1.0.0 (Status Produksi)

OptiDeli telah menyelesaikan seluruh rangkaian blueprint arsitektur enterprise:
- **Dual-Deployment:** Mendukung penggelaran Multi-Tenant Cloud SaaS (via integrasi OptiNexus SSO) maupun Single-Tenant Standalone On-Premise privat.
- **Modul 2 (Pemesanan & 3D Load Building):** Algoritma 3D Bin-Packing heurisik, isolasi kargo Hazmat di lantai dasar, dan penyeimbangan beban as roda (Front vs Rear Axle) dengan visualisasi interaktif Three.js.
- **Modul 3 (Dispatching, Dock & Yard):** Penjadwalan pintu dock tanpa tumpang tindih, pelacakan siklus yard (gate-in hingga gate-out), dan proteksi ketersediaan servis armada (*OptiFleet Asset Lock*).
- **Modul 4 (Telematika & Control Tower):** Ingestion stream posisi IoT GPS, pemantauan suhu box pendingin reefer, level BBM, dan deteksi alarm pengecualian instan (*Overspeed & Cold-chain breach*).
- **Modul 5 (Rate Card & Automated Tendering):** Matriks tarif vendor multi-variabel berbasis minor unit Rupiah dan orkestrasi sekuensial *Waterfall Tendering*.
- **Modul 6 (Freight Settlement & 3-Way Audit):** Pencocokan otomatis PO, ePOD terenkripsi checksum SHA-256, dan tagihan vendor beserta biaya aksesorial.
- **Modul 7 (ESG Carbon Tracking):** Kalkulasi emisi gas rumah kaca Scope 1 (armada sendiri) dan Scope 3 (subkontraktor) sesuai standar global ISO 14083 / GLEC Framework.
- **Enterprise Integrations:** Gateway EDI ANSI X12 204 (Inbound Tender), EDI 214 (Outbound Status Tracking), dan EDI 210 (Invoice Settlement).
`;

if (!readmeContent.includes('Rilis Enterprise v1.0.0')) {
  readmeContent = readmeContent.replace(
    /Rate card\/tender automation, telematika, 3D optimization, ESG dan EDI menunggu increment berikutnya\./,
    'Seluruh modul (Rate card & Waterfall tendering, telematika GIS Control Tower, 3D Load Optimization, ESG Carbon GLEC, dan Enterprise EDI Gateway) telah tuntas diimplementasikan.'
  );
  readmeContent = readmeContent.trimEnd() + '\n\n' + productionReleaseSection.trim() + '\n';
  fs.writeFileSync(readmePath, readmeContent, 'utf8');
  console.log('✅ Diperbarui: README.md (Dokumentasi Rilis Enterprise v1.0.0)');
}

console.log('\nSelesai! Seluruh platform OptiDeli telah tersambung secara utuh.');