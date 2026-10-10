<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
            // Commissions may now come from memberships / gift cards, which have no ecommerce order.
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->decimal('order_amount', 10, 2)->default(0)->change();

            if (!Schema::hasColumn('ecommerce_referral_commissions', 'source_type')) {
                $table->string('source_type', 30)->default('order')->after('order_id'); // order | membership | gift_card
                $table->string('source_ref', 100)->nullable()->after('source_type');
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->unsignedBigInteger('declined_by')->nullable();
                $table->string('decline_reason', 255)->nullable();
                $table->boolean('flagged')->default(false);
                $table->string('flag_reason', 255)->nullable();
                $table->index(['source_type', 'source_ref'], 'erc_source_idx');
            }
        });

        DB::table('ecommerce_referral_commissions')
            ->whereNull('source_ref')
            ->update(['source_type' => 'order', 'source_ref' => DB::raw('order_id')]);

        Schema::table('ecommerce_referrals', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_referrals', 'first_purchase_at')) {
                $table->timestamp('first_purchase_at')->nullable();
                $table->string('ip_address', 64)->nullable();
            }
        });

        Schema::table('ecommerce_affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_affiliates', 'total_clicks')) {
                $table->unsignedInteger('total_clicks')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_affiliates', 'total_clicks')) {
                $table->dropColumn('total_clicks');
            }
        });

        Schema::table('ecommerce_referrals', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_referrals', 'first_purchase_at')) {
                $table->dropColumn(['first_purchase_at', 'ip_address']);
            }
        });

        Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_referral_commissions', 'source_type')) {
                $table->dropIndex('erc_source_idx');
                $table->dropColumn([
                    'source_type', 'source_ref', 'approved_at', 'approved_by',
                    'declined_at', 'declined_by', 'decline_reason', 'flagged', 'flag_reason',
                ]);
            }
        });
    }
};
