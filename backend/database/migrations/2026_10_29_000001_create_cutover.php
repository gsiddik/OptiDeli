<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutover_projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->uuid('customer_id');
            $t->uuid('project_id')->nullable();
            $t->string('legacy_system', 60); // free text: the name of the system being replaced
            $t->string('status', 10)->default('PLANNING'); // PLANNING -> PARALLEL -> CUT_OVER (back to PARALLEL on rollback)
            $t->date('parallel_from')->nullable();
            $t->date('planned_cutover_on')->nullable();
            $t->bigInteger('tolerance_minor')->default(0);
            $t->timestampTz('cut_over_at')->nullable();
            $t->timestampTz('legacy_frozen_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
        });
        DB::statement("ALTER TABLE cutover_projects ADD CONSTRAINT cutover_projects_status_check CHECK (status IN ('PLANNING','PARALLEL','CUT_OVER'))");
        DB::statement("CREATE UNIQUE INDEX cutover_one_per_scope ON cutover_projects (tenant_id, customer_id, coalesce(project_id, '00000000-0000-0000-0000-000000000000'::uuid))");

        Schema::create('cutover_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('cutover_id');
            $t->string('code', 30);
            $t->boolean('done')->default(false);
            $t->foreignId('done_by')->nullable()->constrained('users');
            $t->timestampTz('done_at')->nullable();
            $t->string('note', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['cutover_id', 'code']);
            $t->foreign(['tenant_id', 'cutover_id'])->references(['tenant_id', 'id'])->on('cutover_projects');
        });

        Schema::create('legacy_control_totals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('cutover_id');
            $t->char('period', 7);
            $t->unsignedInteger('spm_count');
            $t->bigInteger('sell_minor');
            $t->bigInteger('buy_minor');
            $t->string('source_note', 200)->nullable();
            $t->foreignId('entered_by')->constrained('users');
            $t->foreignId('accepted_by')->nullable()->constrained('users');
            $t->timestampTz('accepted_at')->nullable();
            $t->string('accept_note', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['cutover_id', 'period']);
            $t->foreign(['tenant_id', 'cutover_id'])->references(['tenant_id', 'id'])->on('cutover_projects');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_control_totals');
        Schema::dropIfExists('cutover_checks');
        DB::statement('DROP INDEX IF EXISTS cutover_one_per_scope');
        Schema::dropIfExists('cutover_projects');
    }
};
