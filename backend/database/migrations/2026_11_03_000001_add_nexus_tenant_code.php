<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tenant code OptiNexus shows to people (TEN-...), so a vendor or customer can sign in to the portal with the code they were given.
        Schema::table('tenants', fn (Blueprint $t) => $t->string('nexus_tenant_code', 80)->nullable());
        DB::statement('CREATE UNIQUE INDEX tenants_nexus_tenant_code_unique ON tenants (lower(nexus_tenant_code)) WHERE nexus_tenant_code IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenants_nexus_tenant_code_unique');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('nexus_tenant_code'));
    }
};
