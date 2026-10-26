<?php

use App\Domain\Master\MasterDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verification of the charges of an SPM, billing to the customer and the vendor bill per SPM (phase F4, modules M6
 * and M7). A selling charge is verified by someone other than the person who made it, and only a VERIFIED selling charge that is not
 * on a live invoice is ready to bill. A billing run groups the ready charges by the customer's invoice template (per SPM, per project or
 * per period, Plan and Unplan together or apart) into customer invoices of the sub-ledger, each with its lines; the one-live-invoice-per-order
 * rule becomes one live invoice line per charge. A vendor bill per SPM is booked only when the vendor's invoice matches the verified
 * buying charges and the AWBs were delivered. Additive: the order-based invoices keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->boolean('active')->default(true);
            $t->string('grouping', 12)->default('PER_SPM');
            $t->boolean('split_plan_unplan')->default(false);
            $t->string('columns', 200)->default('spm,route,description,amount');
            $t->unsignedSmallInteger('due_days')->nullable();
            $t->string('footer', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE invoice_templates ADD CONSTRAINT invoice_templates_grouping_check CHECK (grouping IN ('PER_SPM','PER_PROJECT','PER_PERIOD'))");
        foreach (['customers', 'customer_projects'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->uuid('invoice_template_id')->nullable();
                $t->foreign(['tenant_id', 'invoice_template_id'], "{$table}_invoice_template_fk")->references(['tenant_id', 'id'])->on('invoice_templates');
            });
        }

        Schema::create('billing_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained();
            $t->unsignedSmallInteger('tax_rate_bps')->default(1100);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestampTz('updated_at')->nullable();
        });

        Schema::table('spm_charges', function (Blueprint $t) {
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->text('verify_note')->nullable();
            $t->index(['tenant_id', 'side', 'status']);
        });

        Schema::create('billing_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('customer_id');
            $t->uuid('project_id')->nullable();
            $t->uuid('template_id')->nullable();
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->string('status', 10)->default('ISSUED');
            $t->unsignedInteger('invoice_count')->default(0);
            $t->bigInteger('total_minor')->default(0);
            $t->text('notes')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'number']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('invoice_templates');
        });
        DB::statement("ALTER TABLE billing_batches ADD CONSTRAINT billing_batches_status_check CHECK (status IN ('ISSUED','CANCELLED'))");

        Schema::table('ledger_documents', function (Blueprint $t) {
            $t->uuid('billing_batch_id')->nullable();
            $t->uuid('customer_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->uuid('template_id')->nullable();
            $t->uuid('transport_order_id')->nullable();
            $t->bigInteger('subtotal_minor')->nullable();
            $t->bigInteger('tax_minor')->default(0);
            $t->string('external_reference', 120)->nullable();
            $t->foreign(['tenant_id', 'billing_batch_id'])->references(['tenant_id', 'id'])->on('billing_batches');
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('invoice_templates');
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
        });
        // One live invoice per order stays for the invoices made from an order; the billed ones are limited per charge line instead.
        DB::statement('DROP INDEX IF EXISTS ledger_live_customer_invoice');
        DB::statement("CREATE UNIQUE INDEX ledger_live_customer_invoice ON ledger_documents (tenant_id, order_id) WHERE kind = 'CUSTOMER_INVOICE' AND status <> 'VOID' AND billing_batch_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX ledger_live_vendor_bill_ref ON ledger_documents (tenant_id, vendor_id, external_reference) WHERE kind = 'VENDOR_BILL' AND status <> 'VOID' AND external_reference IS NOT NULL");

        Schema::create('ledger_document_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('document_id');
            $t->uuid('charge_id');
            $t->uuid('transport_order_id');
            $t->string('side', 4);
            $t->string('charge_code', 40);
            $t->string('kind', 6);
            $t->string('description', 255);
            $t->decimal('quantity', 14, 3)->nullable();
            $t->bigInteger('unit_price_minor')->nullable();
            $t->bigInteger('amount_minor');
            $t->boolean('active')->default(true);
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'document_id']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('ledger_documents');
            $t->foreign(['tenant_id', 'charge_id'])->references(['tenant_id', 'id'])->on('spm_charges');
            $t->foreign(['tenant_id', 'transport_order_id'])->references(['tenant_id', 'id'])->on('transport_orders');
        });
        DB::statement('CREATE UNIQUE INDEX ledger_document_lines_live_charge ON ledger_document_lines (charge_id) WHERE active');

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            MasterDefaults::seed($tenantId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_document_lines');
        DB::statement('DROP INDEX IF EXISTS ledger_live_vendor_bill_ref');
        DB::statement('DROP INDEX IF EXISTS ledger_live_customer_invoice');
        DB::statement("CREATE UNIQUE INDEX ledger_live_customer_invoice ON ledger_documents (tenant_id, order_id) WHERE kind = 'CUSTOMER_INVOICE' AND status <> 'VOID'");
        Schema::table('ledger_documents', function (Blueprint $t) {
            foreach (['billing_batch_id', 'customer_id', 'project_id', 'template_id', 'transport_order_id'] as $c) {
                $t->dropForeign(['tenant_id', $c]);
            }
            $t->dropColumn(['billing_batch_id', 'customer_id', 'project_id', 'template_id', 'transport_order_id', 'subtotal_minor', 'tax_minor', 'external_reference']);
        });
        Schema::dropIfExists('billing_batches');
        Schema::table('spm_charges', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'side', 'status']);
            $t->dropColumn(['verified_by', 'verified_at', 'verify_note']);
        });
        Schema::dropIfExists('billing_settings');
        foreach (['customers', 'customer_projects'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_invoice_template_fk");
                $t->dropColumn('invoice_template_id');
            });
        }
        Schema::dropIfExists('invoice_templates');
    }
};
