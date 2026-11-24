<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic refunds for declined / reversed gift card orders: keep the
 * processor's refund id, when it happened and how much was returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_gift_card_orders', 'refund_reference')) {
                $table->string('refund_reference', 100)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_gift_card_orders', 'refunded_at')) {
                $table->dateTime('refunded_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_gift_card_orders', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
            foreach (['refund_reference', 'refunded_at', 'refund_amount'] as $col) {
                if (Schema::hasColumn('ecommerce_gift_card_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
