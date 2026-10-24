<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle availability from OptiFleet (wave 3). `fleet_vehicle_states` is a projection of the optifleet.vehicle.availability_changed
 * events, one row per tenant and registration number; it only ever answers "does OptiFleet say this vehicle is not available",
 * it is never an operational source of truth. `tenants.fleet_availability_token` is the tenant's OptiFleet read credential
 * (stored encrypted by the application, never returned by any API).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_states', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('registration_key', 40);
            $t->string('registration_number', 40);
            $t->string('operational_status', 20);
            $t->string('fleet_status', 30)->nullable();
            $t->uuid('fleet_vehicle_id')->nullable();
            $t->timestampTz('changed_at');
            $t->uuid('last_event_id');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'registration_key']);
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->text('fleet_availability_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('fleet_availability_token'));
        Schema::dropIfExists('fleet_vehicle_states');
    }
};
