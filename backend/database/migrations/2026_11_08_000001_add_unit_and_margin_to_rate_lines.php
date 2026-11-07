<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transporter price mapping: a BUY rate line may be tied to a cargo unit (basis UNIT) and carries the margin that turns the
 * initial rate (unit_price_minor) into the base tariff (sell_unit_price_minor, computed by the service, never typed). A line may
 * also have its own margin for one customer or project (rate_line_margins); those rows are frozen with the approved table.
 * Additive: lines without unit or margin behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_lines', function (Blueprint $t) {
            $t->uuid('unit_id')->nullable();
            $t->string('margin_mode', 8)->nullable();
            $t->bigInteger('margin_value')->nullable();
            $t->bigInteger('sell_unit_price_minor')->nullable();
            $t->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units');
        });
        DB::statement('ALTER TABLE rate_lines DROP CONSTRAINT rate_lines_basis_check');
        DB::statement("ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_basis_check CHECK (basis IN ('TRIP','KG','CBM','CARTON','KM','DAY','DROP','UNIT'))");
        DB::statement("ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_unit_check CHECK ((basis = 'UNIT') = (unit_id IS NOT NULL))");
        DB::statement("ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_margin_check CHECK ((margin_mode IS NULL AND margin_value IS NULL AND sell_unit_price_minor IS NULL) OR (margin_mode IN ('PERCENT','AMOUNT') AND margin_value IS NOT NULL AND margin_value >= 0 AND sell_unit_price_minor IS NOT NULL))");

        Schema::create('rate_line_margins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('rate_line_id');
            $t->uuid('customer_id');
            $t->uuid('project_id')->nullable();
            $t->string('margin_mode', 8);
            $t->bigInteger('margin_value');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'rate_line_id'])->references(['tenant_id', 'id'])->on('rate_lines')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
        });
        DB::statement("ALTER TABLE rate_line_margins ADD CONSTRAINT rate_line_margins_mode_check CHECK (margin_mode IN ('PERCENT','AMOUNT') AND margin_value >= 0)");
        DB::statement('CREATE UNIQUE INDEX rate_line_margins_scope_unique ON rate_line_margins (tenant_id, rate_line_id, customer_id, COALESCE(project_id, \'00000000-0000-0000-0000-000000000000\'::uuid))');
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_line_margins');
        DB::statement('ALTER TABLE rate_lines DROP CONSTRAINT IF EXISTS rate_lines_margin_check');
        DB::statement('ALTER TABLE rate_lines DROP CONSTRAINT IF EXISTS rate_lines_unit_check');
        DB::statement('ALTER TABLE rate_lines DROP CONSTRAINT rate_lines_basis_check');
        DB::statement("ALTER TABLE rate_lines ADD CONSTRAINT rate_lines_basis_check CHECK (basis IN ('TRIP','KG','CBM','CARTON','KM','DAY','DROP'))");
        Schema::table('rate_lines', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'unit_id']);
            $t->dropColumn(['unit_id', 'margin_mode', 'margin_value', 'sell_unit_price_minor']);
        });
    }
};
