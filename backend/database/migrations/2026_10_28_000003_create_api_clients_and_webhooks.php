<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('customer_id');
            $t->string('name', 80);
            $t->string('token_prefix', 12);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('rotated_at')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'customer_id']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
        });

        Schema::create('webhook_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('customer_id');
            $t->string('name', 80);
            $t->string('url', 500);
            $t->text('secret'); // encrypted: needed in clear to sign
            $t->json('events');
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'customer_id', 'active']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('subscription_id');
            $t->string('event_type', 40);
            $t->uuid('subject_id')->nullable();
            $t->json('payload');
            $t->string('status', 8)->default('PENDING');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('next_attempt_at');
            $t->unsignedSmallInteger('last_status_code')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['status', 'next_attempt_at']);
            $t->index(['tenant_id', 'subscription_id', 'created_at']);
            $t->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('webhook_subscriptions');
        });
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('PENDING','SENT','DEAD'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('api_clients');
    }
};
