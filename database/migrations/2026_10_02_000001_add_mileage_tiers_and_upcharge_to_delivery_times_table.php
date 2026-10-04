<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_times', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_times', 'estimated_days')) {
                $table->string('estimated_days')->nullable()->change();
            }
            if (!Schema::hasColumn('delivery_times', 'mileage_tiers')) {
                $table->json('mileage_tiers')->nullable()->after('pricing');
            }
            if (!Schema::hasColumn('delivery_times', 'upcharge')) {
                $table->decimal('upcharge', 10, 2)->default(0.00)->after('mileage_tiers');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_times', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_times', 'upcharge')) {
                $table->dropColumn('upcharge');
            }
            if (Schema::hasColumn('delivery_times', 'mileage_tiers')) {
                $table->dropColumn('mileage_tiers');
            }
        });
    }
};
