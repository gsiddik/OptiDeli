<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 5 (decision K2): OptiDeli keeps a sub-ledger of documents and payments, never a general ledger. The Opti Integration Feed v1
 * publishes every sub-ledger fact in a gap-free, per-tenant sequence for an accounting system (OptiEntry) to pull; accounts and
 * journals are the accounting system's business. Money is a bigint in minor units here and a decimal string on the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('kind', 20); // CUSTOMER_INVOICE (receivable) | VENDOR_BILL (payable)
            $t->string('number');
            $t->uuid('order_id')->nullable();
            $t->uuid('trip_id')->nullable();
            $t->uuid('vendor_id')->nullable();
            $t->uuid('freight_invoice_id')->nullable();
            $t->string('counterparty_name');
            $t->string('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('paid_minor')->default(0);
            $t->date('issue_date');
            $t->date('due_date');
            $t->string('status', 10)->default('OPEN'); // OPEN | PARTIAL | PAID | VOID
            $t->text('void_reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'kind', 'number']);
            $t->index(['tenant_id', 'kind', 'status', 'due_date']);
        });
        Schema::create('ledger_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('document_id');
            $t->string('direction', 12); // RECEIPT (customer pays us) | DISBURSEMENT (we pay a vendor)
            $t->bigInteger('amount_minor');
            $t->date('paid_on');
            $t->string('method', 30);
            $t->string('reference', 120);
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestampTz('created_at');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('ledger_documents');
            $t->unique(['tenant_id', 'document_id', 'reference']);
        });
        Schema::create('ledger_counters', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('kind', 20);
            $t->unsignedBigInteger('last_number')->default(0);
            $t->primary(['tenant_id', 'kind']);
        });
        Schema::create('integration_feed_counters', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained();
            $t->unsignedBigInteger('last_sequence')->default(0);
        });
        Schema::create('integration_feed_events', function (Blueprint $t) {
            $t->uuid('event_id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->unsignedBigInteger('sequence');
            $t->string('event_type', 80);
            $t->string('schema_version', 10)->default('1.0');
            $t->timestampTz('occurred_at');
            $t->string('correlation_id', 80);
            $t->json('source');
            $t->json('dimensions');
            $t->json('payload');
            $t->unique(['tenant_id', 'sequence']);
        });
        Schema::create('integration_feed_credentials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('nexus_tenant_id')->nullable();
            $t->string('consumer_application', 40);
            $t->string('name', 80);
            $t->string('token_prefix', 12);
            $t->string('token_hash', 64)->unique();
            $t->string('scope', 40)->default('feed.read');
            $t->string('issued_by', 80)->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->string('revoked_reason')->nullable();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ledger_documents ADD CONSTRAINT ledger_documents_kind_check CHECK (kind IN ('CUSTOMER_INVOICE','VENDOR_BILL'))");
            DB::statement("ALTER TABLE ledger_documents ADD CONSTRAINT ledger_documents_status_check CHECK (status IN ('OPEN','PARTIAL','PAID','VOID'))");
            DB::statement('ALTER TABLE ledger_documents ADD CONSTRAINT ledger_documents_amount_check CHECK (amount_minor > 0 AND paid_minor >= 0 AND paid_minor <= amount_minor)');
            DB::statement("ALTER TABLE ledger_payments ADD CONSTRAINT ledger_payments_direction_check CHECK (direction IN ('RECEIPT','DISBURSEMENT') AND amount_minor > 0)");
            DB::statement("CREATE UNIQUE INDEX ledger_live_customer_invoice ON ledger_documents(tenant_id, order_id) WHERE kind = 'CUSTOMER_INVOICE' AND status <> 'VOID'");
            DB::statement("CREATE UNIQUE INDEX ledger_live_vendor_bill ON ledger_documents(tenant_id, freight_invoice_id) WHERE kind = 'VENDOR_BILL' AND status <> 'VOID'");
            // Facts are immutable: a payment and a feed event are never changed or removed, a correction is a new event.
            DB::statement("CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '% is append only', TG_TABLE_NAME; END; \$\$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER ledger_payments_append_only BEFORE UPDATE OR DELETE ON ledger_payments FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');
            DB::statement('CREATE TRIGGER integration_feed_events_append_only BEFORE UPDATE OR DELETE ON integration_feed_events FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');
        }
    }

    public function down(): void
    {
        foreach (['integration_feed_credentials', 'integration_feed_events', 'integration_feed_counters', 'ledger_counters', 'ledger_payments', 'ledger_documents'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS ledger_append_only()');
        }
    }
};
