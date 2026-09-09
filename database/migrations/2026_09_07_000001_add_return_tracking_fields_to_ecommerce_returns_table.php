<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_returns', 'return_tracking_carrier')) {
                $table->string('return_tracking_carrier')->nullable()->after('who_pays_shipping');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_tracking_number')) {
                $table->string('return_tracking_number')->nullable()->after('return_tracking_carrier');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_shipping_date')) {
                $table->date('return_shipping_date')->nullable()->after('return_tracking_number');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_estimated_delivery')) {
                $table->date('return_estimated_delivery')->nullable()->after('return_shipping_date');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_shipping_label_urls')) {
                $table->json('return_shipping_label_urls')->nullable()->after('return_estimated_delivery');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_receipt_urls')) {
                $table->json('return_receipt_urls')->nullable()->after('return_shipping_label_urls');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'customer_declaration_confirmed')) {
                $table->boolean('customer_declaration_confirmed')->default(false)->after('return_receipt_urls');
            }
            if (!Schema::hasColumn('ecommerce_returns', 'return_tracking_status')) {
                $table->string('return_tracking_status')->nullable()->after('customer_declaration_confirmed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_returns', function (Blueprint $table) {
            $cols = [
                'return_tracking_carrier',
                'return_tracking_number',
                'return_shipping_date',
                'return_estimated_delivery',
                'return_shipping_label_urls',
                'return_receipt_urls',
                'customer_declaration_confirmed',
                'return_tracking_status',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('ecommerce_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
