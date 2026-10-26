<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SPM (Surat Perintah Muat, the transport order) between an order and its trips (phase F2, module M3).
 * An order can be split into several SPMs; each carries its own shipment lines (PO, SO, material, quantities, seal), a status
 * history, and the charges that were priced from the tariffs when it was planned (both sides). Numbers come from a gap-free
 * sequence per tenant and month. Additive: orders, trips and dispatch keep working as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('kind', 20);
            $t->string('period', 8);
            $t->unsignedBigInteger('last_value')->default(0);
            $t->timestampTz('updated_at')->nullable();
            $t->primary(['tenant_id', 'kind', 'period']);
        });

        Schema::create('transport_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('order_id');
            $t->unsignedSmallInteger('sequence');
            $t->string('status', 12)->default('DRAFT');
            $t->uuid('customer_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->uuid('origin_location_id')->nullable();
            $t->uuid('destination_location_id')->nullable();
            $t->string('origin_text', 300);
            $t->string('destination_text', 300);
            $t->uuid('carrier_type_id')->nullable();
            $t->uuid('vendor_id')->nullable();
            $t->string('reference', 120)->nullable();
            $t->timestampTz('planned_pickup_at')->nullable();
            $t->timestampTz('planned_delivery_at')->nullable();
            $t->text('notes')->nullable();
            $t->text('status_reason')->nullable();
            $t->timestampTz('priced_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'order_id', 'sequence']);
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders');
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
            $t->foreign(['tenant_id', 'origin_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->foreign(['tenant_id', 'destination_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        DB::statement("ALTER TABLE transport_orders ADD CONSTRAINT transport_orders_status_check CHECK (status IN ('DRAFT','PLANNED','LOADING','IN_TRANSIT','DISCHARGED','DOC_VERIFIED','CLOSED','REOPENED','CANCELLED'))");

        Schema::create('shipment_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('transport_order_id');
            $t->unsignedSmallInteger('line_no');
            $t->string('po_number', 60)->nullable();
            $t->string('so_number', 60)->nullable();
            $t->uuid('goods_id')->nullable();
            $t->string('material', 200);
            $t->decimal('quantity', 14, 3);
            $t->uuid('unit_id')->nullable();
            $t->unsignedInteger('pallets')->nullable();
            $t->decimal('volume_cbm', 12, 3)->nullable();
            $t->decimal('weight_kg', 14, 3)->nullable();
            $t->string('seal_number', 60)->nullable();
            $t->timestampTz('slot_time')->nullable();
            $t->decimal('damage_qty', 14, 3)->default(0);
            $t->decimal('loss_qty', 14, 3)->default(0);
            $t->decimal('over_qty', 14, 3)->default(0);
            $t->string('notes', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['transport_order_id', 'line_no']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'goods_id'])->references(['tenant_id', 'id'])->on('goods');
            $t->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units');
        });
        DB::statement('ALTER TABLE shipment_lines ADD CONSTRAINT shipment_lines_quantities_check CHECK (quantity > 0 AND damage_qty >= 0 AND loss_qty >= 0 AND over_qty >= 0)');

        Schema::create('spm_status_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('transport_order_id');
            $t->string('from_status', 12)->nullable();
            $t->string('to_status', 12);
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['transport_order_id', 'created_at']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
        });

        Schema::create('spm_charges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('transport_order_id');
            $t->string('side', 4);
            $t->uuid('charge_code_id');
            $t->string('kind', 6)->default('PLAN');
            $t->string('source', 6)->default('RATE');
            $t->string('basis', 10)->nullable();
            $t->decimal('quantity', 14, 3)->nullable();
            $t->bigInteger('unit_price_minor')->nullable();
            $t->bigInteger('amount_minor');
            $t->uuid('rate_table_id')->nullable();
            $t->string('rate_label', 80)->nullable();
            $t->string('markup_code', 40)->nullable();
            $t->string('status', 10)->default('PROPOSED');
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['transport_order_id', 'side']);
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'charge_code_id'])->references(['tenant_id', 'id'])->on('charge_codes');
            $t->foreign(['tenant_id', 'rate_table_id'])->references(['tenant_id', 'id'])->on('rate_tables');
        });
        DB::statement("ALTER TABLE spm_charges ADD CONSTRAINT spm_charges_side_check CHECK (side IN ('SELL','BUY'))");
        DB::statement("ALTER TABLE spm_charges ADD CONSTRAINT spm_charges_kind_check CHECK (kind IN ('PLAN','UNPLAN'))");
        DB::statement("ALTER TABLE spm_charges ADD CONSTRAINT spm_charges_source_check CHECK (source IN ('RATE','MANUAL','GEOFENCE','CLAIM'))");
        DB::statement("ALTER TABLE spm_charges ADD CONSTRAINT spm_charges_status_check CHECK (status IN ('PROPOSED','VERIFIED','REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('spm_charges');
        Schema::dropIfExists('spm_status_history');
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('transport_orders');
        Schema::dropIfExists('document_sequences');
    }
};
