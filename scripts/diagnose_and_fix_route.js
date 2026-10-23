// scripts/diagnose_and_fix_route.js
const fs = require('fs');
const path = require('path');

console.log('--- 1. MEMERIKSA FILE ROUTES & BOOTSTRAP ---');

const routesDir = path.resolve(process.cwd(), 'backend/routes');
const routeFiles = fs.readdirSync(routesDir);
console.log('File di backend/routes/:', routeFiles);

// Cek isi bootstrap/app.php
const bootstrapAppPath = path.resolve(process.cwd(), 'backend/bootstrap/app.php');
if (fs.existsSync(bootstrapAppPath)) {
  const bootstrapContent = fs.readFileSync(bootstrapAppPath, 'utf8');
  console.log('\nRouting config di bootstrap/app.php:');
  const routingMatch = bootstrapContent.match(/withRouting\([\s\S]*?\)/);
  if (routingMatch) {
    console.log(routingMatch[0]);
  }
}

// Cek di mana rute /health atau /orders berada
let targetRouteFile = 'backend/routes/api.php';
for (const file of routeFiles) {
  const fullPath = path.join(routesDir, file);
  const content = fs.readFileSync(fullPath, 'utf8');
  if (content.includes('health') || content.includes('orders') || content.includes('trips')) {
    console.log(`\n🔍 Ditemukan rute existing di: backend/routes/${file}`);
    targetRouteFile = `backend/routes/${file}`;
  }
}

console.log(`\n--- 2. MENDAFTARKAN ROUTE INCREMENT 2B KE ${targetRouteFile} ---`);

const routeSnippet = `
// ==========================================
// INCREMENT 2B: RATE CARD & WATERFALL TENDER
// ==========================================
use App\\Http\\Controllers\\Api\\VendorRateCardController;
use App\\Http\\Controllers\\Api\\WaterfallTenderController;

Route::prefix('api/v1')->group(function () {
    Route::get('/rate-cards', [VendorRateCardController::class, 'index']);
    Route::post('/rate-cards', [VendorRateCardController::class, 'store']);
    Route::post('/rate-cards/calculate-estimate', [VendorRateCardController::class, 'calculateEstimate']);

    Route::get('/tenders', [WaterfallTenderController::class, 'index']);
    Route::post('/tenders/waterfall/start', [WaterfallTenderController::class, 'startWaterfall']);
    Route::post('/tenders/offers/{offerId}/respond', [WaterfallTenderController::class, 'respondOffer']);
});

Route::prefix('v1')->group(function () {
    Route::get('/rate-cards', [VendorRateCardController::class, 'index']);
    Route::post('/rate-cards', [VendorRateCardController::class, 'store']);
    Route::post('/rate-cards/calculate-estimate', [VendorRateCardController::class, 'calculateEstimate']);

    Route::get('/tenders', [WaterfallTenderController::class, 'index']);
    Route::post('/tenders/waterfall/start', [WaterfallTenderController::class, 'startWaterfall']);
    Route::post('/tenders/offers/{offerId}/respond', [WaterfallTenderController::class, 'respondOffer']);
});
`;

// Pasang ke targetRouteFile (misal web.php atau api.php)
const targetPath = path.resolve(process.cwd(), targetRouteFile);
let content = fs.readFileSync(targetPath, 'utf8');
if (!content.includes('VendorRateCardController')) {
  content = content.trimEnd() + '\n' + routeSnippet + '\n';
  fs.writeFileSync(targetPath, content, 'utf8');
  console.log(`✅ Sukses ditambahkan ke ${targetRouteFile}`);
} else {
  // Replace agar snippet terupdate
  content = content.replace(/\/\/ ==========================================\s*\/\/ INCREMENT 2B: RATE CARD & WATERFALL TENDER[\s\S]*?(?=(\n\/\/|$))/, '');
  content = content.trimEnd() + '\n' + routeSnippet + '\n';
  fs.writeFileSync(targetPath, content, 'utf8');
  console.log(`✅ Snippet diperbarui di ${targetRouteFile}`);
}

// Juga pastikan jika web.php yang dipakai, api.php juga aman
if (targetRouteFile !== 'backend/routes/api.php' && fs.existsSync(path.resolve(process.cwd(), 'backend/routes/api.php'))) {
  const apiPath = path.resolve(process.cwd(), 'backend/routes/api.php');
  let apiContent = fs.readFileSync(apiPath, 'utf8');
  if (!apiContent.includes('VendorRateCardController')) {
    apiContent = apiContent.trimEnd() + '\n' + routeSnippet + '\n';
    fs.writeFileSync(apiPath, apiContent, 'utf8');
  }
}

console.log('\nSelesai!');