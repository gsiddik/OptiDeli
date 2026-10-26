<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planning of an SPM onto vehicles (ARK/OP adoption, phase F2, module M4), as the owner defined it: an SPM carries several air
 * waybills (AWB), each with its own number, consignee and destination and grouping some of the SPM's shipment lines; every AWB is
 * carried by exactly one trip, so one SPM can have several trips. A trip belongs to one SPM, reserves a vehicle and a driver, has
 * an ordered list of stops (the SPM's origin, then the destinations of its AWBs) whose arrival and departure times are recorded
 * by hand now and by geofence later, and is held against the margin policy. It sits beside the existing order-based trips: both
 * kinds are checked against each other for double booking; moving the old flow onto SPMs is the cut-over phase. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spm_trips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('transport_order_id');
            $t->uuid('vehicle_id');
            $t->uuid('driver_id');
            $t->uuid('vendor_id')->nullable();
            $t->string('status', 16)->default('PLANNED');
            $t->boolean('active')->default(true);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->bigInteger('sell_minor')->default(0);
            $t->bigInteger('cost_minor')->default(0);
            $t->unsignedSmallInteger('threshold_bps')->nullable();
            $t->unsignedTinyInteger('required_level')->default(0);
            $t->string('reason_code', 60)->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status', 'starts_at']);
            $t->index(['tenant_id', 'transport_order_id']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
            $t->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('drivers');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        DB::statement("ALTER TABLE spm_trips ADD CONSTRAINT spm_trips_status_check CHECK (status IN ('PENDING_APPROVAL','PLANNED','IN_TRANSIT','COMPLETED','CANCELLED'))");
        DB::statement('ALTER TABLE spm_trips ADD CONSTRAINT spm_trips_period_check CHECK (ends_at > starts_at)');
        DB::statement('CREATE UNIQUE INDEX spm_trips_active_vehicle ON spm_trips (vehicle_id) WHERE active');
        DB::statement('CREATE UNIQUE INDEX spm_trips_active_driver ON spm_trips (driver_id) WHERE active');

        Schema::create('awbs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('transport_order_id');
            $t->unsignedSmallInteger('sequence');
            $t->string('status', 10)->default('PENDING');
            $t->uuid('trip_id')->nullable();
            $t->uuid('destination_location_id');
            $t->string('destination_text', 300);
            $t->string('consignee_name', 200)->nullable();
            $t->string('consignee_phone', 40)->nullable();
            $t->string('notes', 300)->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'number']);
            $t->unique(['transport_order_id', 'sequence']);
            $t->index(['tenant_id', 'trip_id']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('spm_trips');
            $t->foreign(['tenant_id', 'destination_location_id'])->references(['tenant_id', 'id'])->on('locations');
        });
        DB::statement("ALTER TABLE awbs ADD CONSTRAINT awbs_status_check CHECK (status IN ('PENDING','ON_TRIP','DELIVERED','CANCELLED'))");

        Schema::table('shipment_lines', function (Blueprint $t) {
            $t->uuid('awb_id')->nullable();
            $t->foreign(['tenant_id', 'awb_id'])->references(['tenant_id', 'id'])->on('awbs');
        });

        Schema::create('spm_trip_stops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->unsignedSmallInteger('sequence');
            $t->uuid('location_id');
            $t->string('type', 6);
            $t->timestampTz('planned_from')->nullable();
            $t->timestampTz('planned_to')->nullable();
            $t->timestampTz('arrived_at')->nullable();
            $t->timestampTz('departed_at')->nullable();
            $t->string('time_source', 10)->nullable();
            $t->string('notes', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['trip_id', 'sequence']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('spm_trips');
            $t->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations');
        });
        DB::statement("ALTER TABLE spm_trip_stops ADD CONSTRAINT spm_trip_stops_type_check CHECK (type IN ('PICKUP','DROP'))");
        DB::statement("ALTER TABLE spm_trip_stops ADD CONSTRAINT spm_trip_stops_source_check CHECK (time_source IS NULL OR time_source IN ('MANUAL','GEOFENCE','DRIVER'))");

        Schema::table('spm_charges', function (Blueprint $t) {
            $t->uuid('trip_stop_id')->nullable();
            $t->foreign(['tenant_id', 'trip_stop_id'])->references(['tenant_id', 'id'])->on('spm_trip_stops');
        });
    }

    public function down(): void
    {
        Schema::table('spm_charges', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'trip_stop_id']);
            $t->dropColumn('trip_stop_id');
        });
        Schema::dropIfExists('spm_trip_stops');
        Schema::table('shipment_lines', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'awb_id']);
            $t->dropColumn('awb_id');
        });
        Schema::dropIfExists('awbs');
        Schema::dropIfExists('spm_trips');
    }
};
