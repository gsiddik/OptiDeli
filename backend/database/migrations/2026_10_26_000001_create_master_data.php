<?php

use App\Domain\Master\MasterDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master data of the transport operation (ARK/OP adoption, phase F1, module M1): customers and their projects, a location
 * directory with coordinates, the regional reference (region, city, zone, area), carrier types, goods with units and
 * categories, services with a service-level target, and the charge catalogue that tariffs and costs will refer to.
 *
 * Additive: nothing existing changes. Every table is tenant scoped with a composite key, codes are unique per tenant, and a
 * master that is in use is only ever deactivated (there is no delete). Starter units, services and charge codes are added
 * to the tenants that already exist; new tenants get them when they are created (MasterDefaults).
 */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('tenant_id')->constrained();
        $t->string('code', 40);
        $t->string('name', 200);
        $t->boolean('active')->default(true);
        $t->timestampsTz();
        $t->unique(['tenant_id', 'id']);
        $t->unique(['tenant_id', 'code']);
    }

    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $this->base($t);
            $t->string('tax_id', 32)->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->string('pic_name', 150)->nullable();
            $t->string('pic_phone', 40)->nullable();
            $t->string('pic_email', 254)->nullable();
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
        });
        Schema::create('customer_projects', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('customer_id');
            $t->string('contract_reference', 120)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->index(['tenant_id', 'customer_id']);
        });
        Schema::create('regions', function (Blueprint $t) {
            $this->base($t);
        });
        Schema::create('cities', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('region_id');
            $t->foreign(['tenant_id', 'region_id'])->references(['tenant_id', 'id'])->on('regions');
        });
        Schema::create('zones', function (Blueprint $t) {
            $this->base($t);
            $t->string('description', 300)->nullable();
        });
        Schema::create('areas', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('city_id')->nullable();
            $t->uuid('zone_id')->nullable();
            $t->foreign(['tenant_id', 'city_id'])->references(['tenant_id', 'id'])->on('cities');
            $t->foreign(['tenant_id', 'zone_id'])->references(['tenant_id', 'id'])->on('zones');
        });
        Schema::create('locations', function (Blueprint $t) {
            $this->base($t);
            $t->string('type', 20);
            $t->uuid('customer_id')->nullable();
            $t->text('address')->nullable();
            $t->uuid('city_id')->nullable();
            $t->uuid('area_id')->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->unsignedInteger('geofence_radius_m')->nullable();
            $t->string('contact_name', 150)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'city_id'])->references(['tenant_id', 'id'])->on('cities');
            $t->foreign(['tenant_id', 'area_id'])->references(['tenant_id', 'id'])->on('areas');
            $t->unique(['tenant_id', 'name']);
        });
        Schema::create('carrier_types', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedBigInteger('capacity_g')->nullable();
            $t->unsignedInteger('length_mm')->nullable();
            $t->unsignedInteger('width_mm')->nullable();
            $t->unsignedInteger('height_mm')->nullable();
            $t->string('description', 300)->nullable();
        });
        Schema::create('units', function (Blueprint $t) {
            $this->base($t);
        });
        Schema::create('category_types', function (Blueprint $t) {
            $this->base($t);
        });
        Schema::create('goods', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('unit_id')->nullable();
            $t->uuid('category_id')->nullable();
            $t->unsignedInteger('unit_weight_g')->nullable();
            $t->unsignedInteger('length_mm')->nullable();
            $t->unsignedInteger('width_mm')->nullable();
            $t->unsignedInteger('height_mm')->nullable();
            $t->boolean('stackable')->default(true);
            $t->string('hazmat_class', 20)->nullable();
            $t->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units');
            $t->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('category_types');
        });
        Schema::create('services', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedSmallInteger('sla_hours')->nullable();
            $t->string('description', 300)->nullable();
        });
        Schema::create('charge_codes', function (Blueprint $t) {
            $this->base($t);
            $t->string('side', 8);
            $t->string('nature', 20);
            $t->string('fact_key', 60);
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            MasterDefaults::seed($tenantId);
        }
    }

    public function down(): void
    {
        foreach (['charge_codes', 'services', 'goods', 'category_types', 'units', 'carrier_types', 'locations', 'areas', 'zones', 'cities', 'regions', 'customer_projects', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
