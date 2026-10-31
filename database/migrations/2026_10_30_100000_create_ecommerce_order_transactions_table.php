<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One checkout = one gateway charge, recorded as several ledger transactions so the admin can
 * see (and refund) the order, shipping per address, turnaround, tip and each donation separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_order_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('order_number')->nullable()->index();
            // order | shipping | packaging | turnaround | tip | donation
            $table->string('type', 30)->index();
            $table->string('label')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            // completed | partially_refunded | refunded
            $table->string('status', 30)->default('completed');
            $table->string('payment_method')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->string('charity_name')->nullable();
            $table->string('shipment_group')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_order_transactions');
    }
};
