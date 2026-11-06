<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup requests from OptiStash (docs/integration/PICKUP_REQUESTS.md). A request is an inbox record: it becomes an order and an SPM
 * only when the tenant's defaults (or a dispatcher) supply what the event cannot know. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // How a Deli warehouse location is tied to a warehouse of another platform: "optistash:<WAREHOUSE CODE>". Set by an operator, never guessed.
        Schema::table('locations', function (Blueprint $t) {
            $t->string('external_ref', 120)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX locations_external_ref_unique ON locations (tenant_id, external_ref) WHERE external_ref IS NOT NULL');

        Schema::create('pickup_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained();
            $t->boolean('auto_order')->default(false);
            $t->uuid('customer_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->uuid('destination_location_id')->nullable();
            $t->string('stage', 12)->default('LAST_MILE');
            // The orders made without a person are made in the name of whoever last saved these settings.
            $t->foreignId('configured_by')->nullable()->constrained('users');
            $t->timestampTz('updated_at')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
            $t->foreign(['tenant_id', 'destination_location_id'])->references(['tenant_id', 'id'])->on('locations');
        });
        DB::statement("ALTER TABLE pickup_settings ADD CONSTRAINT pickup_settings_stage_check CHECK (stage IN ('FIRST_MILE','MIDDLE_MILE','LAST_MILE','E2E'))");

        Schema::create('pickup_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('source_system', 20);
            $t->uuid('source_event_id');
            $t->string('shipment_number', 60);
            $t->json('warehouse');
            $t->json('items');
            $t->string('status', 10)->default('RECEIVED');
            $t->uuid('warehouse_location_id')->nullable();
            $t->uuid('order_id')->nullable();
            $t->uuid('transport_order_id')->nullable();
            $t->string('auto_failure', 300)->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users');
            $t->timestampTz('handled_at')->nullable();
            $t->timestampTz('occurred_at')->nullable();
            $t->timestampTz('received_at');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'source_system', 'source_event_id']);
            $t->index(['tenant_id', 'status', 'received_at']);
            $t->index(['tenant_id', 'shipment_number']);
            $t->foreign(['tenant_id', 'warehouse_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders');
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
        });
        DB::statement("ALTER TABLE pickup_requests ADD CONSTRAINT pickup_requests_status_check CHECK (status IN ('RECEIVED','CONVERTED','REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_requests');
        Schema::dropIfExists('pickup_settings');
        DB::statement('DROP INDEX IF EXISTS locations_external_ref_unique');
        Schema::table('locations', function (Blueprint $t) {
            $t->dropColumn('external_ref');
        });
    }
};
