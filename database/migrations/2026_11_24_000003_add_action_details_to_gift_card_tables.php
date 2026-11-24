<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift card action popups: every approve / decline / deactivate / reactivate /
 * void / reverse records a reason, a description and (void vs reverse) an action
 * type alongside the existing activity log row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ecommerce_gift_card_activity_logs', 'meta')) {
            Schema::table('ecommerce_gift_card_activity_logs', function (Blueprint $table) {
                $table->json('meta')->nullable();
            });
        }

        if (! Schema::hasColumn('ecommerce_gift_card_orders', 'declined_description')) {
            Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
                $table->text('declined_description')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_gift_card_activity_logs', 'meta')) {
            Schema::table('ecommerce_gift_card_activity_logs', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }

        if (Schema::hasColumn('ecommerce_gift_card_orders', 'declined_description')) {
            Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
                $table->dropColumn('declined_description');
            });
        }
    }
};
