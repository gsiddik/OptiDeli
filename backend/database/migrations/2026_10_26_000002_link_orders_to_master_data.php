<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order may point at the customer, project, service and the pick-up and drop locations kept in the master data. The free
 * text columns (customer_name, origin, destination) stay and become a snapshot of the master at the time of ordering, so
 * existing orders, the API and every report keep working; the links are optional and add the structured reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->uuid('customer_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->uuid('service_id')->nullable();
            $t->uuid('origin_location_id')->nullable();
            $t->uuid('destination_location_id')->nullable();
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('customer_projects');
            $t->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services');
            $t->foreign(['tenant_id', 'origin_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->foreign(['tenant_id', 'destination_location_id'])->references(['tenant_id', 'id'])->on('locations');
            $t->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            foreach (['customer_id', 'project_id', 'service_id', 'origin_location_id', 'destination_location_id'] as $column) {
                $t->dropForeign(['tenant_id', $column]);
            }
            $t->dropIndex(['tenant_id', 'customer_id']);
            $t->dropColumn(['customer_id', 'project_id', 'service_id', 'origin_location_id', 'destination_location_id']);
        });
    }
};
