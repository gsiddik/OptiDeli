<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The identifier the vehicle's tracker reports to OptiRadar (the IMEI of a GPS tracker, a serial number, another ID). Optional: without it
        // OptiRadar makes a placeholder device that an operator completes.
        Schema::table('vehicles', fn (Blueprint $t) => $t->string('tracker_id', 80)->nullable());
        DB::statement('CREATE UNIQUE INDEX vehicles_tracker_id_unique ON vehicles (tenant_id, lower(tracker_id)) WHERE tracker_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vehicles_tracker_id_unique');
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn('tracker_id'));
    }
};
