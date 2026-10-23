<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('vendor_id')->index();
            $table->string('vehicle_type');
            $table->string('zone_origin');
            $table->string('zone_destination');
            $table->bigInteger('base_rate_minor_units');
            $table->bigInteger('rate_per_km_minor_units')->default(0);
            $table->integer('min_distance_km')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vendor_id', 'is_active']);
            $table->unique(['tenant_id', 'vendor_id', 'vehicle_type', 'zone_origin', 'zone_destination'], 'uq_rate_card_matrix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_rate_cards');
    }
};
