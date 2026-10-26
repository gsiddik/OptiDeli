<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seats of a tenant in OptiDeli: the people who can sign in to run it (its own staff and its vendors' users; customers' portal users
 * do not count). The count is kept per day (the day's peak) so that a month can be reported to OptiNexus, which bills what is above
 * the seats the subscription includes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_snapshots', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->date('day');
            $t->unsignedInteger('internal');
            $t->unsignedInteger('vendor');
            $t->unsignedInteger('total');
            $t->timestampTz('updated_at');
            $t->primary(['tenant_id', 'day']);
            $t->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('seat_reports', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->char('period', 7);
            $t->unsignedInteger('quantity');
            $t->string('status', 6); // SENT | FAILED
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error', 300)->nullable();
            $t->timestampTz('reported_at')->nullable();
            $t->timestampsTz();
            $t->primary(['tenant_id', 'period']);
            $t->foreign('tenant_id')->references('id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_reports');
        Schema::dropIfExists('seat_snapshots');
    }
};
