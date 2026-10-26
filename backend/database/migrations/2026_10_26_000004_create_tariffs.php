<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-sided, versioned tariffs (ARK/OP adoption, phase F1, module M2). A rate table is one version of one tariff (SELL to a
 * customer or project, or BUY from a vendor or the own fleet) with a validity period; it is drafted, then approved by someone
 * other than its author, and from then on only retired or superseded by a new version. Its lines say what a charge costs on a
 * lane, for a carrier type and service, per trip/kg/CBM/carton/km/day/drop. Markup rules turn a BUY price into a SELL price
 * for the customers that have no fixed selling tariff. Additive: the existing vendor_rate_cards (tendering) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_tables', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->unsignedSmallInteger('version')->default(1);
            $t->string('name', 200);
            $t->string('side', 4);
            $t->uuid('customer_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->uuid('vendor_id')->nullable();
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->string('status', 10)->default('DRAFT');
            $t->text('notes')->nullable();
            $t->text('status_reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code', 'version']);
            $t->index(['tenant_id', 'side', 'status']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        DB::statement("ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_side_check CHECK (side IN ('SELL','BUY'))");
        DB::statement("ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_status_check CHECK (status IN ('DRAFT','APPROVED','RETIRED'))");
        DB::statement("ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_party_check CHECK ((side = 'SELL' AND vendor_id IS NULL) OR (side = 'BUY' AND customer_id IS NULL AND project_id IS NULL))");
        DB::statement('ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_project_customer_check CHECK (project_id IS NULL OR customer_id IS NOT NULL)');
        DB::statement('ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_period_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        Schema::create('rate_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('rate_table_id');
            $t->uuid('origin_location_id')->nullable();
            $t->uuid('origin_city_id')->nullable();
            $t->uuid('origin_zone_id')->nullable();
            $t->uuid('destination_location_id')->nullable();
            $t->uuid('destination_city_id')->nullable();
            $t->uuid('destination_zone_id')->nullable();
            $t->uuid('carrier_type_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->uuid('charge_code_id');
            $t->string('basis', 10);
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('min_charge_minor')->default(0);
            $t->unsignedSmallInteger('free_time_hours')->nullable();
            $t->string('notes', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'rate_table_id']);
            $t->foreign(['tenant_id', 'rate_table_id'])->references(['tenant_id', 'id'])->on('rate_tables');
            foreach (['origin_location_id', 'destination_location_id'] as $column) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('locations');
            }
            foreach (['origin_city_id', 'destination_city_id'] as $column) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('cities');
            }
            foreach (['origin_zone_id', 'destination_zone_id'] as $column) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('zones');
            }
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
            $t->foreign(['tenant_id', 'charge_code_id'])->references(['tenant_id', 'id'])->on('charge_codes');
        });
        DB::statement("ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_basis_check CHECK (basis IN ('TRIP','KG','CBM','CARTON','KM','DAY','DROP'))");
        DB::statement('ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_price_check CHECK (unit_price_minor >= 0 AND min_charge_minor >= 0)');
        DB::statement('ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_one_origin_check CHECK (num_nonnulls(origin_location_id, origin_city_id, origin_zone_id) <= 1)');
        DB::statement('ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_one_destination_check CHECK (num_nonnulls(destination_location_id, destination_city_id, destination_zone_id) <= 1)');

        Schema::create('markup_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->string('mode', 8);
            $t->unsignedInteger('percent_bps')->nullable();
            $t->bigInteger('amount_minor')->nullable();
            $t->uuid('customer_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->uuid('carrier_type_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->uuid('origin_city_id')->nullable();
            $t->uuid('destination_city_id')->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'carrier_type_id'])->references(['tenant_id', 'id'])->on('carrier_types');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
            $t->foreign(['tenant_id', 'origin_city_id'])->references(['tenant_id', 'id'])->on('cities');
            $t->foreign(['tenant_id', 'destination_city_id'])->references(['tenant_id', 'id'])->on('cities');
        });
        DB::statement("ALTER TABLE markup_rules ADD CONSTRAINT markup_rules_mode_check CHECK ((mode = 'PERCENT' AND percent_bps IS NOT NULL AND amount_minor IS NULL) OR (mode = 'AMOUNT' AND amount_minor IS NOT NULL AND percent_bps IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('markup_rules');
        Schema::dropIfExists('rate_lines');
        Schema::dropIfExists('rate_tables');
    }
};
