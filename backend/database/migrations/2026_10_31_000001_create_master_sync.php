<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master data shared with the other platforms (docs/integration/MASTER_DATA_SYNC.md): the asset attributes OptiFleet owns,
 * a vehicle created from another platform that still lacks its transport data, and the outbox toward OptiNexus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vehicles ALTER COLUMN type DROP NOT NULL');
        foreach (['capacity_g', 'length_mm', 'width_mm', 'height_mm'] as $column) {
            DB::statement("ALTER TABLE vehicles ALTER COLUMN {$column} DROP NOT NULL");
        }
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('brand', 100)->nullable();
            $t->string('model', 100)->nullable();
            $t->unsignedSmallInteger('vehicle_year')->nullable();
            $t->string('vin', 40)->nullable();
            $t->string('source', 20)->default('LOCAL');
            $t->boolean('transport_complete')->default(true);
        });
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_source_check CHECK (source IN ('LOCAL','OPTIFLEET','OPTIRADAR'))");
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_transport_complete_check CHECK (NOT transport_complete OR (capacity_g IS NOT NULL AND length_mm IS NOT NULL AND width_mm IS NOT NULL AND height_mm IS NOT NULL))');

        Schema::create('master_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('event_key', 80);
            $t->uuid('aggregate_id');
            $t->jsonb('payload');
            $t->string('status', 12)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('last_attempted_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE master_events ADD CONSTRAINT master_events_status_check CHECK (status IN ('PENDING','DELIVERED','FAILED'))");

        // The newest version applied per vehicle and producing platform, so an old delivery never overwrites a newer one.
        Schema::create('master_versions', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->string('registration_key', 40);
            $t->string('source', 20);
            $t->unsignedBigInteger('version');
            $t->primary(['tenant_id', 'registration_key', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_versions');
        Schema::dropIfExists('master_events');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_transport_complete_check');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_source_check');
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['brand', 'model', 'vehicle_year', 'vin', 'source', 'transport_complete']));
    }
};
