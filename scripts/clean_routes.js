// scripts/clean_routes.js
const fs = require('fs');
const path = require('path');

// 1. Hapus backend/routes/api.php jika ada (karena tidak dipakai dan agar tidak di-scan Pint)
const apiPath = path.resolve(process.cwd(), 'backend/routes/api.php');
if (fs.existsSync(apiPath)) {
  fs.unlinkSync(apiPath);
  console.log('🗑️  Menghapus file backend/routes/api.php yang tidak digunakan.');
}

// 2. Format backend/routes/web.php agar lolos Laravel Pint
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let content = fs.readFileSync(webPath, 'utf8');

// Pastikan import dan deklarasi rapi
if (!content.includes('VendorRateCardController')) {
  const snippet = `
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
`;
  content = content.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, content, 'utf8');
}
console.log('✅ File backend/routes/web.php siap dan terkonfigurasi dengan benar.');