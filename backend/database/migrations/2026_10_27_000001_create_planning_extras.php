<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the SPM planning (phase F2) and the first part of tracking (F5): route templates for the stops of a
 * trip, vehicle reservations (a booking that is converted into a trip), the queue of requests to re-open an SPM, and what the
 * geofence capture needs - a credential for the machine that sends positions and the log of every ENTER/EXIT decision. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->uuid('origin_location_id')->nullable();
            $t->uuid('carrier_type_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->string('notes', 300)->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
            $t->foreign(['tenant_id', 'origin_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
        });

        Schema::create('route_template_stops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('template_id');
            $t->unsignedSmallInteger('sequence');
            $t->uuid('location_id');
            $t->string('type', 6);
            $t->unsignedInteger('offset_minutes')->default(0);
            $t->unique(['template_id', 'sequence']);
            $t->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('route_templates');
            $t->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('locations');
        });
        DB::statement("ALTER TABLE route_template_stops ADD CONSTRAINT route_template_stops_type_check CHECK (type IN ('PICKUP','DROP'))");

        Schema::create('vehicle_reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('vehicle_id');
            $t->uuid('driver_id')->nullable();
            $t->uuid('transport_order_id')->nullable();
            $t->uuid('trip_id')->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('purpose', 300)->nullable();
            $t->string('status', 10)->default('BOOKED');
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'vehicle_id', 'starts_at']);
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
            $t->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('drivers');
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('spm_trips');
        });
        DB::statement("ALTER TABLE vehicle_reservations ADD CONSTRAINT vehicle_reservations_status_check CHECK (status IN ('BOOKED','CONVERTED','CANCELLED'))");
        DB::statement('ALTER TABLE vehicle_reservations ADD CONSTRAINT vehicle_reservations_period_check CHECK (ends_at > starts_at)');

        Schema::create('spm_reopen_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('transport_order_id');
            $t->string('from_status', 12);
            $t->text('reason');
            $t->string('status', 10)->default('PENDING');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
        });
        DB::statement("ALTER TABLE spm_reopen_requests ADD CONSTRAINT spm_reopen_requests_status_check CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED'))");
        DB::statement("CREATE UNIQUE INDEX spm_reopen_one_pending ON spm_reopen_requests (transport_order_id) WHERE status = 'PENDING'");

        Schema::create('tracking_credentials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name', 80);
            $t->string('token_prefix', 12);
            $t->string('token_hash', 64)->unique();
            $t->foreignId('acts_as')->constrained('users');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('stop_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->uuid('stop_id')->nullable();
            $t->uuid('vehicle_id');
            $t->string('event', 5);
            $t->string('source', 10);
            $t->timestampTz('occurred_at');
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('applied')->default(false);
            $t->string('ignored_reason', 60)->nullable();
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'trip_id', 'created_at']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('spm_trips');
            $t->foreign(['tenant_id', 'stop_id'])->references(['tenant_id', 'id'])->on('spm_trip_stops');
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
        });
        DB::statement("ALTER TABLE stop_events ADD CONSTRAINT stop_events_event_check CHECK (event IN ('ENTER','EXIT'))");
        DB::statement("ALTER TABLE stop_events ADD CONSTRAINT stop_events_source_check CHECK (source IN ('GEOFENCE','RADAR','MANUAL','DRIVER'))");

        Schema::table('locations', function (Blueprint $t) {
            $t->string('radar_geofence_id', 80)->nullable();
        });
        // Stop times can also come from OptiRadar's own geofence engine.
        DB::statement('ALTER TABLE spm_trip_stops DROP CONSTRAINT spm_trip_stops_source_check');
        DB::statement("ALTER TABLE spm_trip_stops ADD CONSTRAINT spm_trip_stops_source_check CHECK (time_source IS NULL OR time_source IN ('MANUAL','GEOFENCE','DRIVER','RADAR'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE spm_trip_stops DROP CONSTRAINT spm_trip_stops_source_check');
        DB::statement("ALTER TABLE spm_trip_stops ADD CONSTRAINT spm_trip_stops_source_check CHECK (time_source IS NULL OR time_source IN ('MANUAL','GEOFENCE','DRIVER'))");
        Schema::table('locations', function (Blueprint $t) {
            $t->dropColumn('radar_geofence_id');
        });
        Schema::dropIfExists('stop_events');
        Schema::dropIfExists('tracking_credentials');
        Schema::dropIfExists('spm_reopen_requests');
        Schema::dropIfExists('vehicle_reservations');
        Schema::dropIfExists('route_template_stops');
        Schema::dropIfExists('route_templates');
    }
};
