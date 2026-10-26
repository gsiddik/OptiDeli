<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            $t->uuid('customer_id')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'customer_id']);
            $t->dropColumn('customer_id');
        });
    }
};
