<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Update order_id foreign key so deleting an order does NOT cascade-delete
     * financial audit commissions; sets order_id to null instead.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_referral_commissions')) {
            Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
                try {
                    $table->dropForeign(['order_id']);
                } catch (\Throwable $e) {
                    // Foreign key name may differ or not exist in some environments
                }

                try {
                    $table->foreign('order_id')
                        ->references('id')
                        ->on('ecommerce_orders')
                        ->nullOnDelete();
                } catch (\Throwable $e) {
                    // Ignore if foreign key creation fails (e.g. SQLite testing)
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ecommerce_referral_commissions')) {
            Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
                try {
                    $table->dropForeign(['order_id']);
                    $table->foreign('order_id')
                        ->references('id')
                        ->on('ecommerce_orders')
                        ->cascadeOnDelete();
                } catch (\Throwable $e) {}
            });
        }
    }
};
