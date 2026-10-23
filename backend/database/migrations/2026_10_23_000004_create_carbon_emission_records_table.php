<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_emission_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id');
            $table->string('fleet_ownership'); // INTERNAL | VENDOR
            $table->string('fuel_type'); // DIESEL_B35 | DIESEL_EURO4 | CNG | EV
            $table->float('distance_km');
            $table->float('cargo_weight_ton');
            $table->float('ton_km');
            $table->float('emission_factor_g_per_ton_km');
            $table->float('total_co2e_kg');
            $table->string('scope'); // SCOPE_1 | SCOPE_3
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_emission_records');
    }
};
