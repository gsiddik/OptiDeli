<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders priced from the transporter price mapping. An order may name its transporter (a vendor, or nobody = the own fleet),
 * a vehicle and the cargo unit of every line; the server then computes the price (price_source MAPPING), keeps what it was
 * computed from (pricing_snapshot) and locks it when the order is confirmed. A line without a matching mapping row can carry a
 * manual price with a reason; the order then waits for a second person (price_status PENDING) before it can be confirmed.
 * Existing orders keep their typed prices (price_source MANUAL, price_status FIXED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->uuid('transporter_vendor_id')->nullable();
            $t->uuid('vehicle_id')->nullable();
            $t->uuid('carrier_type_id')->nullable();
            $t->string('price_source', 10)->default('MANUAL');
            $t->string('price_status', 10)->default('FIXED');
            $t->jsonb('pricing_snapshot')->nullable();
            $t->text('price_reason')->nullable();
            $t->foreignId('price_requested_by')->nullable()->constrained('users');
            $t->foreignId('price_decided_by')->nullable()->constrained('users');
            $t->timestampTz('price_decided_at')->nullable();
            $t->text('price_decision_note')->nullable();
            $t->timestampTz('price_locked_at')->nullable();
            $t->foreign(['tenant_id', 'transporter_vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_price_source_check CHECK (price_source IN ('MANUAL','MAPPING','APPROVAL'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_price_status_check CHECK (price_status IN ('FIXED','PENDING','APPROVED','REJECTED'))");

        Schema::table('order_items', function (Blueprint $t) {
            $t->uuid('unit_id')->nullable();
            $t->bigInteger('manual_price_minor')->nullable();
            $t->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_manual_price_check CHECK (manual_price_minor IS NULL OR manual_price_minor > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_manual_price_check');
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'unit_id']);
            $t->dropColumn(['unit_id', 'manual_price_minor']);
        });
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_price_status_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_price_source_check');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'transporter_vendor_id']);
            $t->dropForeign(['tenant_id', 'vehicle_id']);
            $t->dropForeign(['tenant_id', 'carrier_type_id']);
            $t->dropConstrainedForeignId('price_requested_by');
            $t->dropConstrainedForeignId('price_decided_by');
            $t->dropColumn(['transporter_vendor_id', 'vehicle_id', 'carrier_type_id', 'price_source', 'price_status', 'pricing_snapshot', 'price_reason', 'price_decided_at', 'price_decision_note', 'price_locked_at']);
        });
    }
};
