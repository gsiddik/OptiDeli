<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver cash (uang jalan) and the cost lines it produces (ARK/OP adoption, phase F3, module M5). A voucher is asked for a trip,
 * approved by someone else within a limit that depends on the permission, paid out of a cashbox, and settled with the actual
 * amounts and a receipt for each. Every movement of cash is an append-only ledger entry on the cashbox and on the driver, so
 * the balance of a cashbox and the cash a driver is expected to hold are sums, never edited numbers. This is the counter-party's
 * cash, not the company's books: the facts go to OptiEntry through the feed, where they are mapped to accounts. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained();
            $t->bigInteger('approval_limit_minor')->default(500_000_000);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestampTz('updated_at')->nullable();
        });

        Schema::create('cashboxes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('cash_vouchers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('trip_id');
            $t->uuid('transport_order_id');
            $t->uuid('driver_id');
            $t->uuid('cashbox_id')->nullable();
            $t->string('status', 10)->default('REQUESTED');
            $t->string('purpose', 300)->nullable();
            $t->bigInteger('requested_minor');
            $t->bigInteger('approved_minor')->nullable();
            $t->bigInteger('disbursed_minor')->nullable();
            $t->bigInteger('actual_minor')->nullable();
            $t->bigInteger('returned_minor')->nullable();
            $t->bigInteger('reimbursed_minor')->nullable();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignId('disbursed_by')->nullable()->constrained('users');
            $t->timestampTz('disbursed_at')->nullable();
            $t->foreignId('settled_by')->nullable()->constrained('users');
            $t->timestampTz('settled_at')->nullable();
            $t->text('status_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->foreign(['tenant_id', 'trip_id'])->references(['tenant_id', 'id'])->on('spm_trips');
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
            $t->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('drivers');
            $t->foreign(['tenant_id', 'cashbox_id'])->references(['tenant_id', 'id'])->on('cashboxes');
        });
        DB::statement("ALTER TABLE cash_vouchers ADD CONSTRAINT cash_vouchers_status_check CHECK (status IN ('REQUESTED','APPROVED','REJECTED','DISBURSED','SETTLED','CANCELLED'))");
        DB::statement('ALTER TABLE cash_vouchers ADD CONSTRAINT cash_vouchers_requested_check CHECK (requested_minor > 0)');

        Schema::create('cash_voucher_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('voucher_id');
            $t->uuid('charge_code_id');
            $t->string('kind', 6);
            $t->bigInteger('planned_minor')->nullable();
            $t->bigInteger('requested_minor');
            $t->bigInteger('approved_minor')->nullable();
            $t->bigInteger('actual_minor')->nullable();
            $t->string('receipt_ref', 120)->nullable();
            $t->text('reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'voucher_id'])->references(['tenant_id', 'id'])->on('cash_vouchers');
            $t->foreign(['tenant_id', 'charge_code_id'])->references(['tenant_id', 'id'])->on('charge_codes');
        });
        DB::statement("ALTER TABLE cash_voucher_lines ADD CONSTRAINT cash_voucher_lines_kind_check CHECK (kind IN ('PLAN','UNPLAN'))");
        DB::statement('ALTER TABLE cash_voucher_lines ADD CONSTRAINT cash_voucher_lines_amount_check CHECK (requested_minor > 0)');

        Schema::create('cash_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('cashbox_id')->nullable();
            $t->uuid('driver_id')->nullable();
            $t->uuid('voucher_id')->nullable();
            $t->string('entry_type', 12);
            $t->bigInteger('amount_minor');
            $t->string('memo', 300)->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'cashbox_id']);
            $t->index(['tenant_id', 'driver_id']);
            $t->foreign(['tenant_id', 'cashbox_id'])->references(['tenant_id', 'id'])->on('cashboxes');
            $t->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('drivers');
            $t->foreign(['tenant_id', 'voucher_id'])->references(['tenant_id', 'id'])->on('cash_vouchers');
        });
        DB::statement("ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_type_check CHECK (entry_type IN ('TOPUP','DISBURSE','EXPENSE','RETURN','REIMBURSE'))");
        DB::statement('ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_holder_check CHECK (cashbox_id IS NOT NULL OR driver_id IS NOT NULL)');
        DB::statement('ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_amount_check CHECK (amount_minor <> 0)');
        DB::statement('ALTER TABLE cash_ledger ADD COLUMN seq BIGINT GENERATED ALWAYS AS IDENTITY');
        DB::statement("CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '% is append only', TG_TABLE_NAME; END; \$\$ LANGUAGE plpgsql");
        DB::statement('CREATE TRIGGER cash_ledger_append_only BEFORE UPDATE OR DELETE ON cash_ledger FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');

        Schema::table('spm_charges', function (Blueprint $t) {
            $t->uuid('cash_voucher_id')->nullable();
            $t->foreign(['tenant_id', 'cash_voucher_id'])->references(['tenant_id', 'id'])->on('cash_vouchers');
        });
    }

    public function down(): void
    {
        Schema::table('spm_charges', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'cash_voucher_id']);
            $t->dropColumn('cash_voucher_id');
        });
        DB::statement('DROP TRIGGER IF EXISTS cash_ledger_append_only ON cash_ledger');
        Schema::dropIfExists('cash_ledger');
        Schema::dropIfExists('cash_voucher_lines');
        Schema::dropIfExists('cash_vouchers');
        Schema::dropIfExists('cashboxes');
        Schema::dropIfExists('cash_settings');
    }
};
