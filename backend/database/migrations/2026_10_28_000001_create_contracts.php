<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-cost vehicle contracts (ARK/OP adoption, phase F6, module M9): a customer contracts a vehicle for a monthly fee that includes
 * a number of trips, with a price for each trip above it; the vehicle's own monthly cost (vendor rental) is the buying side. The
 * vehicle's attendance prorates both fees. A monthly run turns a contract into selling and buying charges that are verified like
 * the charges of an SPM, billed with them (a billing line may now point at a contract charge instead of an SPM charge) and counted in the
 * contribution P&L. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixcost_contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->uuid('customer_id');
            $t->uuid('project_id')->nullable();
            $t->uuid('vehicle_id');
            $t->uuid('vendor_id')->nullable();
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->bigInteger('monthly_sell_minor');
            $t->bigInteger('monthly_buy_minor')->default(0);
            $t->unsignedSmallInteger('trips_included')->default(0);
            $t->bigInteger('overage_sell_minor')->default(0);
            $t->bigInteger('overage_buy_minor')->default(0);
            $t->boolean('prorate')->default(true);
            $t->unsignedSmallInteger('working_days')->default(26);
            $t->string('status', 8)->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
            $t->index(['tenant_id', 'vehicle_id']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        DB::statement("ALTER TABLE fixcost_contracts ADD CONSTRAINT fixcost_contracts_status_check CHECK (status IN ('ACTIVE','ENDED'))");
        DB::statement('ALTER TABLE fixcost_contracts ADD CONSTRAINT fixcost_contracts_amounts_check CHECK (monthly_sell_minor >= 0 AND monthly_buy_minor >= 0 AND overage_sell_minor >= 0 AND overage_buy_minor >= 0 AND working_days BETWEEN 1 AND 31)');
        DB::statement('ALTER TABLE fixcost_contracts ADD CONSTRAINT fixcost_contracts_period_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        Schema::create('vehicle_attendance', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('vehicle_id');
            $t->uuid('driver_id')->nullable();
            $t->date('work_date');
            $t->string('status', 8);
            $t->string('notes', 300)->nullable();
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'vehicle_id', 'work_date']);
            $t->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles');
            $t->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('drivers');
        });
        DB::statement("ALTER TABLE vehicle_attendance ADD CONSTRAINT vehicle_attendance_status_check CHECK (status IN ('PRESENT','STANDBY','ABSENT','OFF'))");

        Schema::create('contract_charges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('contract_id');
            $t->char('period', 7);
            $t->string('side', 4);
            $t->string('kind', 8);
            $t->string('description', 255);
            $t->decimal('quantity', 14, 3)->nullable();
            $t->bigInteger('unit_price_minor')->nullable();
            $t->bigInteger('amount_minor');
            $t->json('calculation')->nullable();
            $t->string('status', 10)->default('PROPOSED');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->text('verify_note')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'contract_id', 'period']);
            $t->foreign(['tenant_id', 'contract_id'])->references(['tenant_id', 'id'])->on('fixcost_contracts');
        });
        DB::statement("ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_side_check CHECK (side IN ('SELL','BUY'))");
        DB::statement("ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_kind_check CHECK (kind IN ('FIXED','OVERAGE'))");
        DB::statement("ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_status_check CHECK (status IN ('PROPOSED','VERIFIED','REJECTED'))");
        DB::statement("CREATE UNIQUE INDEX contract_charges_live ON contract_charges (contract_id, period, side, kind) WHERE status <> 'REJECTED'");

        // A billing line may now point at a contract charge instead of an SPM charge.
        Schema::table('ledger_document_lines', function (Blueprint $t) {
            $t->uuid('contract_charge_id')->nullable();
            $t->foreign(['tenant_id', 'contract_charge_id'])->references(['tenant_id', 'id'])->on('contract_charges');
        });
        DB::statement('ALTER TABLE ledger_document_lines ALTER COLUMN charge_id DROP NOT NULL');
        DB::statement('ALTER TABLE ledger_document_lines ALTER COLUMN transport_order_id DROP NOT NULL');
        DB::statement('ALTER TABLE ledger_document_lines ADD CONSTRAINT ledger_document_lines_source_check CHECK ((charge_id IS NOT NULL) <> (contract_charge_id IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX ledger_document_lines_live_contract_charge ON ledger_document_lines (contract_charge_id) WHERE active AND contract_charge_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_document_lines_live_contract_charge');
        DB::statement('ALTER TABLE ledger_document_lines DROP CONSTRAINT IF EXISTS ledger_document_lines_source_check');
        DB::table('ledger_document_lines')->whereNotNull('contract_charge_id')->delete();
        DB::statement('ALTER TABLE ledger_document_lines ALTER COLUMN charge_id SET NOT NULL');
        DB::statement('ALTER TABLE ledger_document_lines ALTER COLUMN transport_order_id SET NOT NULL');
        Schema::table('ledger_document_lines', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'contract_charge_id']);
            $t->dropColumn('contract_charge_id');
        });
        Schema::dropIfExists('contract_charges');
        Schema::dropIfExists('vehicle_attendance');
        Schema::dropIfExists('fixcost_contracts');
    }
};
