<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->uuid('order_id')->index();
            $table->string('tendering_mode')->default('WATERFALL');
            $table->string('status')->default('RUNNING');
            $table->integer('current_sequence')->default(1);
            $table->integer('timeout_minutes_per_vendor')->default(30);
            $table->string('accepted_vendor_id')->nullable();
            $table->bigInteger('winning_cost_minor_units')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('tender_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->uuid('tender_dispatch_id')->index();
            $table->string('vendor_id')->index();
            $table->integer('sequence')->default(1);
            $table->bigInteger('offered_cost_minor_units');
            $table->string('status')->default('PENDING');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('tender_dispatch_id')->references('id')->on('tender_dispatches')->onDelete('cascade');
            $table->index(['tenant_id', 'vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_offers');
        Schema::dropIfExists('tender_dispatches');
    }
};
