<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor-owned fleet. A vendor registers its own vehicles (with their vehicle type, which is what the buying rate follows) and
 * drivers through its portal and uploads their documents; the tenant verifies them. Only a verified, active vehicle and driver
 * of the vendor can be assigned to an SPM of that vendor. Own-fleet rows are unaffected: they are verified from the start.
 * A vendor can also propose a buying rate (a draft BUY tariff of its own); the tenant approves it like any other tariff.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Documents of a vendor vehicle or driver are only known after they are uploaded and verified.
        foreach (['vehicles' => ['documents_valid_until', 'readiness_checked_at'], 'drivers' => ['license_valid_until', 'eligibility_verified_until']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
            }
        }

        Schema::table('vehicles', function (Blueprint $t) {
            $t->uuid('vendor_id')->nullable();
            $t->uuid('carrier_type_id')->nullable();
            $t->boolean('active')->default(true);
            $t->string('verification_status', 10)->default('VERIFIED');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->string('verification_note', 500)->nullable();
            $t->foreignId('registered_by')->nullable()->constrained('users');
            $t->index(['tenant_id', 'vendor_id']);
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
        });
        Schema::table('drivers', function (Blueprint $t) {
            $t->uuid('vendor_id')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('verification_status', 10)->default('VERIFIED');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->string('verification_note', 500)->nullable();
            $t->foreignId('registered_by')->nullable()->constrained('users');
            $t->index(['tenant_id', 'vendor_id']);
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        foreach (['vehicles', 'drivers'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_check CHECK (verification_status IN ('PENDING','VERIFIED','REJECTED'))");
            // A vendor's own rows start unverified; the default only serves the tenant's own fleet.
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_vendor_verified_check CHECK (vendor_id IS NULL OR verification_status <> 'VERIFIED' OR verified_by IS NOT NULL)");
        }

        Schema::create('fleet_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('owner_type', 8); // VEHICLE | DRIVER
            $t->uuid('owner_id');
            $t->string('kind', 6); // STNK | KIR | SIM | KTP
            $t->string('number', 80)->nullable();
            $t->date('valid_until')->nullable();
            $t->string('path');
            $t->string('mime', 60);
            $t->unsignedInteger('size_bytes');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['owner_type', 'owner_id', 'kind']);
            $t->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement("ALTER TABLE fleet_documents ADD CONSTRAINT fleet_documents_owner_check CHECK (owner_type IN ('VEHICLE','DRIVER'))");
        DB::statement("ALTER TABLE fleet_documents ADD CONSTRAINT fleet_documents_kind_check CHECK ((owner_type = 'VEHICLE' AND kind IN ('STNK','KIR')) OR (owner_type = 'DRIVER' AND kind IN ('SIM','KTP')))");

        Schema::table('rate_tables', function (Blueprint $t) {
            $t->boolean('proposed_by_vendor')->default(false);
            $t->timestampTz('submitted_at')->nullable();
            $t->string('review_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rate_tables', fn (Blueprint $t) => $t->dropColumn(['proposed_by_vendor', 'submitted_at', 'review_note']));
        Schema::dropIfExists('fleet_documents');
        foreach (['vehicles', 'drivers'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_vendor_verified_check");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_verification_check");
        }
        Schema::table('drivers', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'vendor_id']);
            $t->dropForeign(['verified_by']);
            $t->dropForeign(['registered_by']);
            $t->dropColumn(['vendor_id', 'phone', 'verification_status', 'verified_by', 'verified_at', 'verification_note', 'registered_by']);
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'vendor_id']);
            $t->dropForeign(['tenant_id', 'carrier_type_id']);
            $t->dropForeign(['verified_by']);
            $t->dropForeign(['registered_by']);
            $t->dropColumn(['vendor_id', 'carrier_type_id', 'active', 'verification_status', 'verified_by', 'verified_at', 'verification_note', 'registered_by']);
        });
    }
};
