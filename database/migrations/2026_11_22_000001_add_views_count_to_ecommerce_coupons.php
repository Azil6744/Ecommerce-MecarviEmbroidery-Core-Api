<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Deals are stored as ecommerce_coupons; views_count backs the "Views" stat on the deals manager. */
    public function up(): void
    {
        if (! Schema::hasColumn('ecommerce_coupons', 'views_count')) {
            Schema::table('ecommerce_coupons', function (Blueprint $table) {
                $table->unsignedBigInteger('views_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_coupons', 'views_count')) {
            Schema::table('ecommerce_coupons', function (Blueprint $table) {
                $table->dropColumn('views_count');
            });
        }
    }
};
