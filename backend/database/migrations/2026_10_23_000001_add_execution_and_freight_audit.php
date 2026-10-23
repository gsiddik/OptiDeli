<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name');
            $t->string('code');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
        });
        Schema::table('memberships', function (Blueprint $t) {
            $t->uuid('vendor_id')->nullable();
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        Schema::table('trips', function (Blueprint $t) {
            $t->dropUnique(['vehicle_id']);
            $t->dropUnique(['driver_id']);
            $t->dropUnique(['order_id']);
            $t->unique(['tenant_id', 'id']);
            $t->boolean('active')->default(true);
            $t->uuid('vendor_id')->nullable();
            $t->string('contract_reference')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        foreach (['vehicle_id', 'driver_id', 'order_id'] as $column) {
            DB::statement("CREATE UNIQUE INDEX trips_active_{$column} ON trips ({$column}) WHERE active");
        }
        Schema::create('trip_legs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->unsignedInteger('sequence');
            $t->string('origin');
            $t->string('destination');
            $t->timestampTz('planned_eta');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('arrived_at')->nullable();
            $t->string('status')->default('PLANNED');
            $t->unique(['tenant_id', 'id']);
            $t->unique(['trip_id', 'sequence']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('trips');
        });
        Schema::create('proofs_of_delivery', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->string('receiver_name');
            $t->unsignedInteger('delivered_units');
            $t->string('outcome');
            $t->text('note')->nullable();
            $t->string('path');
            $t->string('mime');
            $t->string('sha256', 64);
            $t->string('status')->default('SUBMITTED');
            $t->foreignId('submitted_by')->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('trips');
        });
        DB::statement("CREATE UNIQUE INDEX pod_verified_trip ON proofs_of_delivery(trip_id) WHERE status = 'VERIFIED'");
        Schema::create('docks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name');
            $t->string('hub');
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'hub', 'name']);
        });
        Schema::create('dock_appointments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('dock_id');
            $t->uuid('trip_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('status')->default('BOOKED');
            $t->timestampTz('gate_in_at')->nullable();
            $t->timestampTz('dock_in_at')->nullable();
            $t->timestampTz('dock_out_at')->nullable();
            $t->timestampTz('gate_out_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'dock_id'])->references(['tenant_id', 'id'])->on('docks');
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('trips');
        });
        Schema::create('accessorial_claims', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->string('category');
            $t->bigInteger('amount_minor');
            $t->text('reason');
            $t->uuid('appointment_id')->nullable();
            $t->string('evidence_reference');
            $t->string('status')->default('SUBMITTED');
            $t->foreignId('submitted_by')->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->text('verification_note')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('trips');
            $t->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('dock_appointments');
        });
        Schema::create('freight_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('trip_id');
            $t->uuid('vendor_id');
            $t->string('number');
            $t->bigInteger('base_minor');
            $t->bigInteger('accessorial_minor');
            $t->bigInteger('rounding_minor')->default(0);
            $t->bigInteger('total_minor');
            $t->string('document_reference');
            $t->string('path');
            $t->string('mime');
            $t->string('sha256', 64);
            $t->string('status')->default('ON_HOLD');
            $t->string('hold_reason');
            $t->string('approval_tier');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('submitted_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('trips');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        DB::statement("CREATE UNIQUE INDEX invoice_live_trip ON freight_invoices(trip_id) WHERE status <> 'VOID'");
        DB::statement("CREATE UNIQUE INDEX invoice_live_vendor_number ON freight_invoices(tenant_id,vendor_id,number) WHERE status <> 'VOID'");
        Schema::create('invoice_approvals', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id');
            $t->uuid('invoice_id');
            $t->unsignedInteger('version');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('authority');
            $t->text('note');
            $t->timestampTz('created_at');
            $t->unique(['invoice_id', 'version', 'actor_id']);
            $t->unique(['invoice_id', 'version', 'authority']);
            $t->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('freight_invoices');
        });
        Schema::create('freight_disputes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('invoice_id');
            $t->text('message');
            $t->string('system_key')->nullable();
            $t->unique(['invoice_id', 'system_key']);
            $t->string('status')->default('OPEN');
            $t->text('vendor_response')->nullable();
            $t->text('resolution')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('freight_invoices');
        });
    }

    public function down(): void
    {
        foreach (['vehicle_id', 'driver_id', 'order_id'] as $column) {
            if (DB::table('trips')->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Execution history requires forward migration or backup restoration.');
            }
        }

        foreach (['freight_disputes', 'invoice_approvals', 'freight_invoices', 'accessorial_claims', 'dock_appointments', 'docks', 'proofs_of_delivery', 'trip_legs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('memberships', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'vendor_id']);
            $t->dropColumn('vendor_id');
        });
        // Refuse a lossy rollback once resources have legitimately been reused; restore a backup instead.
        foreach (['vehicle_id', 'driver_id', 'order_id'] as $column) {
            DB::statement("DROP INDEX trips_active_{$column}");
        }
        Schema::table('trips', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'vendor_id']);
            $t->dropUnique(['tenant_id', 'id']);
            $t->dropColumn(['active', 'vendor_id', 'contract_reference', 'started_at', 'completed_at', 'cancellation_reason']);
            $t->unique('vehicle_id');
            $t->unique('driver_id');
            $t->unique('order_id');
        });
        Schema::dropIfExists('vendors');
    }
};
