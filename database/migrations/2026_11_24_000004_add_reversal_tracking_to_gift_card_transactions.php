<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin "Reverse" undoes one gift card ledger transaction. The original row is
 * stamped so it can only ever be reversed once (no duplicate credits/refunds),
 * and the reversing row points back at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_gift_card_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_gift_card_transactions', 'reversed_at')) {
                $table->dateTime('reversed_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_gift_card_transactions', 'reversed_by')) {
                $table->unsignedBigInteger('reversed_by')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_gift_card_transactions', 'reversal_of_id')) {
                $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_gift_card_transactions', function (Blueprint $table) {
            foreach (['reversed_at', 'reversed_by', 'reversal_of_id'] as $col) {
                if (Schema::hasColumn('ecommerce_gift_card_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
