<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug')->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('minimum_margin_bps')->default(1500);
            $t->json('stage_margin_bps')->nullable();
            $t->unsignedInteger('policy_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role');
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number');
            $t->string('customer_name');
            $t->string('reference')->nullable();
            $t->string('stage');
            $t->string('origin');
            $t->string('destination');
            $t->string('currency', 3)->default('IDR');
            $t->bigInteger('base_price_minor');
            $t->bigInteger('surcharge_minor')->default(0);
            $t->bigInteger('accessorial_minor')->default(0);
            $t->bigInteger('sell_minor');
            $t->string('status')->default('DRAFT');
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('order_id');
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_weight_g');
            $t->unsignedInteger('length_mm');
            $t->unsignedInteger('width_mm');
            $t->unsignedInteger('height_mm');
            $t->boolean('stackable')->default(false);
            $t->string('hazmat_class')->nullable();
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders');
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('plate');
            $t->string('type');
            $t->string('status')->default('AVAILABLE');
            $t->bigInteger('capacity_g');
            $t->unsignedInteger('length_mm');
            $t->unsignedInteger('width_mm');
            $t->unsignedInteger('height_mm');
            $t->date('documents_valid_until');
            $t->timestampTz('readiness_checked_at');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'plate']);
        });
        Schema::create('drivers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name');
            $t->string('license_number');
            $t->date('license_valid_until');
            $t->timestampTz('eligibility_verified_until');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'license_number']);
        });
        Schema::create('dispatch_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('order_id');
            $t->uuid('vehicle_id');
            $t->uuid('driver_id');
            $t->bigInteger('vendor_base_minor');
            $t->bigInteger('vendor_accessorial_minor');
            $t->bigInteger('cost_minor');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->text('load_safety_attestation');
            $t->string('reason_code')->nullable();
            $t->text('reason_detail')->nullable();
            $t->unsignedInteger('required_level');
            $t->unsignedInteger('policy_version');
            $t->unsignedInteger('threshold_bps');
            $t->string('status');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            foreach (['order_id' => 'orders', 'vehicle_id' => 'vehicles', 'driver_id' => 'drivers'] as $key => $table) {
                $t->foreign(['tenant_id', $key])->references(['tenant_id', 'id'])->on($table);
            }
        });
        Schema::create('trips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('plan_id')->unique();
            $t->uuid('order_id')->unique();
            $t->uuid('vehicle_id')->unique();
            $t->uuid('driver_id')->unique();
            $t->string('status')->default('ASSIGNED');
            $t->timestampsTz();
            foreach (['plan_id' => 'dispatch_plans', 'order_id' => 'orders', 'vehicle_id' => 'vehicles', 'driver_id' => 'drivers'] as $key => $table) {
                $t->foreign(['tenant_id', $key])->references(['tenant_id', 'id'])->on($table);
            }
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->uuid('subject_id');
            $t->json('metadata');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('type');
            $t->json('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('published_at')->nullable();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id');
            $t->string('operation');
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->uuid('resource_id');
            $t->unique(['tenant_id', 'operation', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'outbox_events', 'audit_events', 'trips', 'dispatch_plans', 'drivers', 'vehicles', 'order_items', 'orders', 'memberships', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
