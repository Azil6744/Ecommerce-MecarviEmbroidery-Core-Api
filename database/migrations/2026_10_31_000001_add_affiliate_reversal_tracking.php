<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cumulative amount already refunded / reversed per commission and payout, so partial
     * refunds and reversals can never deduct more than once or exceed the original amount.
     */
    public function up(): void
    {
        Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_referral_commissions', 'refunded_amount')) {
                $table->decimal('refunded_amount', 10, 2)->default(0);
            }
        });

        Schema::table('ecommerce_affiliate_payouts', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_affiliate_payouts', 'reversed_amount')) {
                $table->decimal('reversed_amount', 10, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_referral_commissions', 'refunded_amount')) {
                $table->dropColumn('refunded_amount');
            }
        });

        Schema::table('ecommerce_affiliate_payouts', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_affiliate_payouts', 'reversed_amount')) {
                $table->dropColumn('reversed_amount');
            }
        });
    }
};
