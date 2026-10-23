<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id')->index();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('speed_kmh')->default(0);
            $table->float('fuel_level_pct')->default(100);
            $table->float('reefer_temp_celsius')->nullable();
            $table->boolean('is_door_open')->default(false);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'vehicle_id', 'recorded_at']);
        });

        Schema::create('telemetry_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id')->index();
            $table->string('alert_type'); // OVERSPEED, REEFER_TEMP_BREACH, DOOR_OPEN_ENROUTE
            $table->string('severity'); // WARNING, CRITICAL
            $table->string('message');
            $table->float('metric_value')->nullable();
            $table->float('threshold_value')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_alerts');
        Schema::dropIfExists('telemetry_logs');
    }
};
