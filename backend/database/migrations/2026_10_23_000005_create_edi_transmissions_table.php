<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edi_transmissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('transaction_set'); // 204, 214, 210
            $table->string('direction'); // INBOUND, OUTBOUND
            $table->string('reference_id')->index(); // Order ID / Shipment ID / Invoice ID
            $table->longText('raw_content'); // Raw ANSI X12 / Payload
            $table->json('parsed_payload')->nullable();
            $table->string('status')->default('PROCESSED'); // PROCESSED, TRANSMITTED, FAILED
            $table->text('error_message')->nullable();
            $table->timestamp('transmitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'transaction_set', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edi_transmissions');
    }
};
