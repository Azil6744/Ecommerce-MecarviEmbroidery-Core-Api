<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an order paid with a voucher is cancelled/refunded, a NEW voucher is issued for the refunded
 * amount. refunded_from_voucher_id links that new voucher to the original voucher that was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ecommerce_vouchers', 'refunded_from_voucher_id')) {
            return;
        }
        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            $table->foreignId('refunded_from_voucher_id')->nullable()->after('user_id')
                ->constrained('ecommerce_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ecommerce_vouchers', 'refunded_from_voucher_id')) {
            return;
        }
        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_from_voucher_id');
        });
    }
};
