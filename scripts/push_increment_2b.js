// scripts/push_increment_2b.js
const OWNER = 'gsiddik';
const REPO = 'OptiDeli';
const BASE_BRANCH = 'main';
const NEW_BRANCH = 'feat/tms-vendor-ratecard-tendering';
const GITHUB_TOKEN = process.env.GITHUB_TOKEN;

if (!GITHUB_TOKEN) {
  console.error("❌ ERROR: Atur GITHUB_TOKEN terlebih dahulu!");
  console.error("Di Windows PowerShell: $env:GITHUB_TOKEN='ghp_xxx'");
  console.error("Di Windows CMD: set GITHUB_TOKEN=ghp_xxx");
  process.exit(1);
}

// BACA KONTEN DARI FILE YANG SUDAH KITA DESIGN
const files = [
  {
    path: 'backend/database/migrations/2026_10_23_000001_create_vendor_rate_cards_table.php',
    content: `<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void\n    {\n        Schema::create('vendor_rate_cards', function (Blueprint $table) {\n            $table->uuid('id')->primary();\n            $table->string('tenant_id')->index();\n            $table->string('vendor_id')->index();\n            $table->string('vehicle_type');\n            $table->string('zone_origin');\n            $table->string('zone_destination');\n            $table->bigInteger('base_rate_minor_units');\n            $table->bigInteger('rate_per_km_minor_units')->default(0);\n            $table->integer('min_distance_km')->default(0);\n            $table->boolean('is_active')->default(true);\n            $table->timestamp('effective_from')->useCurrent();\n            $table->timestamp('effective_to')->nullable();\n            $table->timestamps();\n\n            $table->index(['tenant_id', 'vendor_id', 'is_active']);\n            $table->unique(['tenant_id', 'vendor_id', 'vehicle_type', 'zone_origin', 'zone_destination'], 'uq_rate_card_matrix');\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('vendor_rate_cards');\n    }\n};`
  },
  {
    path: 'backend/database/migrations/2026_10_23_000002_create_tender_dispatches_and_offers_table.php',
    content: `<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void\n    {\n        Schema::create('tender_dispatches', function (Blueprint $table) {\n            $table->uuid('id')->primary();\n            $table->string('tenant_id')->index();\n            $table->uuid('order_id')->index();\n            $table->string('tendering_mode')->default('WATERFALL');\n            $table->string('status')->default('RUNNING');\n            $table->integer('current_sequence')->default(1);\n            $table->integer('timeout_minutes_per_vendor')->default(30);\n            $table->string('accepted_vendor_id')->nullable();\n            $table->bigInteger('winning_cost_minor_units')->nullable();\n            $table->timestamp('started_at')->useCurrent();\n            $table->timestamp('completed_at')->nullable();\n            $table->timestamps();\n\n            $table->index(['tenant_id', 'order_id']);\n        });\n\n        Schema::create('tender_offers', function (Blueprint $table) {\n            $table->uuid('id')->primary();\n            $table->string('tenant_id')->index();\n            $table->uuid('tender_dispatch_id')->index();\n            $table->string('vendor_id')->index();\n            $table->integer('sequence')->default(1);\n            $table->bigInteger('offered_cost_minor_units');\n            $table->string('status')->default('PENDING');\n            $table->timestamp('offered_at')->nullable();\n            $table->timestamp('expires_at')->nullable();\n            $table->timestamp('responded_at')->nullable();\n            $table->string('rejection_reason')->nullable();\n            $table->timestamps();\n\n            $table->foreign('tender_dispatch_id')->references('id')->on('tender_dispatches')->onDelete('cascade');\n            $table->index(['tenant_id', 'vendor_id', 'status']);\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('tender_offers');\n        Schema::dropIfExists('tender_dispatches');\n    }\n};`
  },
  {
    path: 'backend/app/Models/VendorRateCard.php',
    content: `<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass VendorRateCard extends Model\n{\n    use HasUuids;\n\n    protected $table = 'vendor_rate_cards';\n\n    protected $fillable = [\n        'tenant_id',\n        'vendor_id',\n        'vehicle_type',\n        'zone_origin',\n        'zone_destination',\n        'base_rate_minor_units',\n        'rate_per_km_minor_units',\n        'min_distance_km',\n        'is_active',\n        'effective_from',\n        'effective_to',\n    ];\n\n    protected $casts = [\n        'base_rate_minor_units' => 'integer',\n        'rate_per_km_minor_units' => 'integer',\n        'min_distance_km' => 'integer',\n        'is_active' => 'boolean',\n        'effective_from' => 'datetime',\n        'effective_to' => 'datetime',\n    ];\n}`
  },
  {
    path: 'backend/app/Models/TenderDispatch.php',
    content: `<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasMany;\n\nclass TenderDispatch extends Model\n{\n    use HasUuids;\n\n    protected $table = 'tender_dispatches';\n\n    protected $fillable = [\n        'tenant_id',\n        'order_id',\n        'tendering_mode',\n        'status',\n        'current_sequence',\n        'timeout_minutes_per_vendor',\n        'accepted_vendor_id',\n        'winning_cost_minor_units',\n        'started_at',\n        'completed_at',\n    ];\n\n    protected $casts = [\n        'current_sequence' => 'integer',\n        'timeout_minutes_per_vendor' => 'integer',\n        'winning_cost_minor_units' => 'integer',\n        'started_at' => 'datetime',\n        'completed_at' => 'datetime',\n    ];\n\n    public function offers(): HasMany\n    {\n        return $this->hasMany(TenderOffer::class, 'tender_dispatch_id')->orderBy('sequence');\n    }\n}`
  },
  {
    path: 'backend/app/Models/TenderOffer.php',
    content: `<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n\nclass TenderOffer extends Model\n{\n    use HasUuids;\n\n    protected $table = 'tender_offers';\n\n    protected $fillable = [\n        'tenant_id',\n        'tender_dispatch_id',\n        'vendor_id',\n        'sequence',\n        'offered_cost_minor_units',\n        'status',\n        'offered_at',\n        'expires_at',\n        'responded_at',\n        'rejection_reason',\n    ];\n\n    protected $casts = [\n        'sequence' => 'integer',\n        'offered_cost_minor_units' => 'integer',\n        'offered_at' => 'datetime',\n        'expires_at' => 'datetime',\n        'responded_at' => 'datetime',\n    ];\n\n    public function dispatch(): BelongsTo\n    {\n        return $this->belongsTo(TenderDispatch::class, 'tender_dispatch_id');\n    }\n}`
  },
  {
    path: 'backend/app/Services/Tendering/RateCardEngineService.php',
    content: `<?php\n\nnamespace App\\Services\\Tendering;\n\nuse App\\Models\\VendorRateCard;\nuse InvalidArgumentException;\n\nclass RateCardEngineService\n{\n    public function calculateEstimatedCost(\n        string $tenantId,\n        string $vendorId,\n        string $vehicleType,\n        string $zoneOrigin,\n        string $zoneDestination,\n        float $actualDistanceKm\n    ): int {\n        $rateCard = VendorRateCard::where('tenant_id', $tenantId)\n            ->where('vendor_id', $vendorId)\n            ->where('vehicle_type', $vehicleType)\n            ->where('zone_origin', $zoneOrigin)\n            ->where('zone_destination', $zoneDestination)\n            ->where('is_active', true)\n            ->first();\n\n        if (!$rateCard) {\n            throw new InvalidArgumentException("Tarif Rate Card tidak ditemukan untuk vendor '{$vendorId}' pada rute {$zoneOrigin} -> {$zoneDestination}");\n        }\n\n        $baseRate = $rateCard->base_rate_minor_units;\n        $billableDistanceKm = max(0.0, $actualDistanceKm - $rateCard->min_distance_km);\n        $distanceSurcharge = (int) round($billableDistanceKm * $rateCard->rate_per_km_minor_units);\n\n        return $baseRate + $distanceSurcharge;\n    }\n}`
  },
  {
    path: 'backend/app/Services/Tendering/WaterfallTenderEngineService.php',
    content: `<?php\n\nnamespace App\\Services\\Tendering;\n\nuse App\\Models\\TenderDispatch;\nuse App\\Models\\TenderOffer;\nuse Carbon\\Carbon;\nuse Illuminate\\Support\\Facades\\DB;\nuse RuntimeException;\n\nclass WaterfallTenderEngineService\n{\n    public function __construct(\n        protected RateCardEngineService $rateCardEngine\n    ) {}\n\n    public function initiateWaterfall(\n        string $tenantId,\n        string $orderId,\n        array $rankedVendors,\n        int $timeoutMinutes = 30\n    ): TenderDispatch {\n        if (empty($rankedVendors)) {\n            throw new RuntimeException("Daftar peringkat vendor tidak boleh kosong untuk memicu waterfall tender.");\n        }\n\n        return DB::transaction(function () use ($tenantId, $orderId, $rankedVendors, $timeoutMinutes) {\n            $dispatch = TenderDispatch::create([\n                'tenant_id' => $tenantId,\n                'order_id' => $orderId,\n                'tendering_mode' => 'WATERFALL',\n                'status' => 'RUNNING',\n                'current_sequence' => 1,\n                'timeout_minutes_per_vendor' => $timeoutMinutes,\n                'started_at' => Carbon::now(),\n            ]);\n\n            $sequence = 1;\n            foreach ($rankedVendors as $v) {\n                $costMinorUnits = $this->rateCardEngine->calculateEstimatedCost(\n                    $tenantId,\n                    $v['vendor_id'],\n                    $v['vehicle_type'],\n                    $v['zone_origin'],\n                    $v['zone_destination'],\n                    $v['distance_km']\n                );\n\n                TenderOffer::create([\n                    'tenant_id' => $tenantId,\n                    'tender_dispatch_id' => $dispatch->id,\n                    'vendor_id' => $v['vendor_id'],\n                    'sequence' => $sequence,\n                    'offered_cost_minor_units' => $costMinorUnits,\n                    'status' => $sequence === 1 ? 'OFFERED' : 'PENDING',\n                    'offered_at' => $sequence === 1 ? Carbon::now() : null,\n                    'expires_at' => $sequence === 1 ? Carbon::now()->addMinutes($timeoutMinutes) : null,\n                ]);\n\n                $sequence++;\n            }\n\n            return $dispatch->load('offers');\n        });\n    }\n\n    public function processVendorResponse(\n        string $tenantId,\n        string $offerId,\n        string $action,\n        ?string $reason = null\n    ): TenderDispatch {\n        return DB::transaction(function () use ($tenantId, $offerId, $action, $reason) {\n            $offer = TenderOffer::where('tenant_id', $tenantId)\n                ->where('id', $offerId)\n                ->lockForUpdate()\n                ->firstOrFail();\n\n            if ($offer->status !== 'OFFERED') {\n                throw new RuntimeException("Tawaran ini tidak lagi berstatus aktif (status: {$offer->status}).");\n            }\n\n            $dispatch = TenderDispatch::where('tenant_id', $tenantId)\n                ->where('id', $offer->tender_dispatch_id)\n                ->lockForUpdate()\n                ->firstOrFail();\n\n            $now = Carbon::now();\n\n            if ($action === 'ACCEPT') {\n                $offer->update([\n                    'status' => 'ACCEPTED',\n                    'responded_at' => $now,\n                ]);\n\n                TenderOffer::where('tender_dispatch_id', $dispatch->id)\n                    ->where('sequence', '>', $offer->sequence)\n                    ->update(['status' => 'BYPASSED']);\n\n                $dispatch->update([\n                    'status' => 'ACCEPTED',\n                    'accepted_vendor_id' => $offer->vendor_id,\n                    'winning_cost_minor_units' => $offer->offered_cost_minor_units,\n                    'completed_at' => $now,\n                ]);\n            } else {\n                $offer->update([\n                    'status' => 'REJECTED',\n                    'responded_at' => $now,\n                    'rejection_reason' => $reason ?? 'Vendor menolak penugasan.',\n                ]);\n\n                $this->escalateNextVendor($dispatch, $offer->sequence + 1);\n            }\n\n            return $dispatch->fresh(['offers']);\n        });\n    }\n\n    protected function escalateNextVendor(TenderDispatch $dispatch, int $nextSequence): void\n    {\n        $nextOffer = TenderOffer::where('tender_dispatch_id', $dispatch->id)\n            ->where('sequence', $nextSequence)\n            ->lockForUpdate()\n            ->first();\n\n        if ($nextOffer) {\n            $now = Carbon::now();\n            $nextOffer->update([\n                'status' => 'OFFERED',\n                'offered_at' => $now,\n                'expires_at' => $now->copy()->addMinutes($dispatch->timeout_minutes_per_vendor),\n            ]);\n\n            $dispatch->update(['current_sequence' => $nextSequence]);\n        } else {\n            $dispatch->update([\n                'status' => 'EXHAUSTED',\n                'completed_at' => Carbon::now(),\n            ]);\n        }\n    }\n}`
  },
  {
    path: 'frontend/src/types/tendering.ts',
    content: `export interface VendorRateCard {\n  id: string;\n  tenant_id: string;\n  vendor_id: string;\n  vehicle_type: string;\n  zone_origin: string;\n  zone_destination: string;\n  base_rate_minor_units: number;\n  rate_per_km_minor_units: number;\n  min_distance_km: number;\n  is_active: boolean;\n}\n\nexport type TenderOfferStatus = 'PENDING' | 'OFFERED' | 'ACCEPTED' | 'REJECTED' | 'EXPIRED' | 'BYPASSED';\n\nexport interface TenderOffer {\n  id: string;\n  vendor_id: string;\n  sequence: number;\n  offered_cost_minor_units: number;\n  status: TenderOfferStatus;\n  offered_at: string | null;\n  expires_at: string | null;\n  rejection_reason?: string | null;\n}\n\nexport interface TenderDispatch {\n  id: string;\n  order_id: string;\n  tendering_mode: 'WATERFALL' | 'BROADCAST';\n  status: 'RUNNING' | 'ACCEPTED' | 'EXHAUSTED' | 'CANCELLED';\n  current_sequence: number;\n  timeout_minutes_per_vendor: number;\n  accepted_vendor_id: string | null;\n  winning_cost_minor_units: number | null;\n  started_at: string;\n  offers: TenderOffer[];\n}\n\nexport const formatRupiahFromMinor = (minorUnits: number): string => {\n  const rupiah = minorUnits / 100;\n  return new Intl.NumberFormat('id-ID', {\n    style: 'currency',\n    currency: 'IDR',\n    maximumFractionDigits: 0,\n  }).format(rupiah);\n};`
  }
];

async function api(endpoint, method = 'GET', body = null) {
  const res = await fetch(`https://api.github.com/repos/${OWNER}/${REPO}${endpoint}`, {
    method,
    headers: {
      'Authorization': `Bearer ${GITHUB_TOKEN}`,
      'Accept': 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'Content-Type': 'application/json'
    },
    body: body ? JSON.stringify(body) : null
  });
  const data = await res.json();
  if (!res.ok) throw new Error(JSON.stringify(data));
  return data;
}

async function main() {
  try {
    console.log(`1. Mengambil SHA commit terakhir dari branch '${BASE_BRANCH}'...`);
    const ref = await api(`/git/ref/heads/${BASE_BRANCH}`);
    const latestCommitSha = ref.object.sha;

    console.log(`2. Mengambil base tree...`);
    const commit = await api(`/git/commits/${latestCommitSha}`);
    const baseTreeSha = commit.tree.sha;

    console.log(`3. Membuat Git Tree baru dengan ${files.length} file...`);
    const tree = await api('/git/trees', 'POST', {
      base_tree: baseTreeSha,
      tree: files.map(f => ({ path: f.path, mode: '100644', type: 'blob', content: f.content }))
    });

    console.log(`4. Membuat commit baru...`);
    const newCommit = await api('/git/commits', 'POST', {
      message: 'feat(tendering): implement Increment 2B Rate Card Matrix and Waterfall Engine',
      tree: tree.sha,
      parents: [latestCommitSha]
    });

    console.log(`5. Menyiapkan branch '${NEW_BRANCH}'...`);
    try {
      await api('/git/refs', 'POST', { ref: `refs/heads/${NEW_BRANCH}`, sha: newCommit.sha });
    } catch {
      await api(`/git/refs/heads/${NEW_BRANCH}`, 'PATCH', { sha: newCommit.sha, force: true });
    }

    console.log(`6. Membuat Pull Request ke '${BASE_BRANCH}'...`);
    const pr = await api('/pulls', 'POST', {
      title: 'feat: add Increment 2B - Rate Card Matrix & Waterfall Tendering Engine',
      head: NEW_BRANCH,
      base: BASE_BRANCH,
      body: `## Ringkasan Increment 2B (Rate Card & Tendering)
- **Migrations & Models**: \`vendor_rate_cards\`, \`tender_dispatches\`, dan \`tender_offers\`.
- **Financial Minor Units Engine**: Perhitungan tarif dalam integer minor units IDR (1 unit = Rp 0,01) tanpa pembulatan mengambang.
- **Waterfall Engine**: Sequential vendor dispatching dengan pessimistic concurrency lock (\`lockForUpdate\`) dan fallback cascade status.
- **Frontend TSX**: Tipe data terstandarisasi \`types/tendering.ts\`.`
    });

    console.log(`\n🎉 SUKSES BESAR! Pull Request telah terbuat:\n🔗 ${pr.html_url}`);
  } catch (err) {
    console.error('❌ Terjadi kesalahan:', err.message);
  }
}

main();