<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $t) {
            $t->string('source', 20)->default('LOCAL');
            // A driver OptiRadar announced is a draft until its licence validity and eligibility are completed here.
            $t->boolean('profile_complete')->default(true);
            $t->string('radar_tag', 120)->nullable();
        });
        DB::statement("ALTER TABLE drivers ADD CONSTRAINT drivers_source_check CHECK (source IN ('LOCAL','OPTIRADAR'))");
        // Licence numbers are longer than plates.
        DB::statement('ALTER TABLE master_versions ALTER COLUMN registration_key TYPE VARCHAR(100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE drivers DROP CONSTRAINT IF EXISTS drivers_source_check');
        Schema::table('drivers', fn (Blueprint $t) => $t->dropColumn(['source', 'profile_complete', 'radar_tag']));
    }
};
