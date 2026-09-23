<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add tax_settings to site_settings table
        Schema::table('site_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('site_settings', 'tax_settings')) {
                $table->text('tax_settings')->nullable()->after('tax_enabled');
            }
        });

        // 2. Add shipping_taxable and effective_date to ecommerce_tax_rates
        Schema::table('ecommerce_tax_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_tax_rates', 'shipping_taxable')) {
                $table->boolean('shipping_taxable')->default(true)->after('rate');
            }
            if (!Schema::hasColumn('ecommerce_tax_rates', 'effective_date')) {
                $table->string('effective_date')->default('Jan 1, 2024')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            if (Schema::hasColumn('site_settings', 'tax_settings')) {
                $table->dropColumn('tax_settings');
            }
        });

        Schema::table('ecommerce_tax_rates', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_tax_rates', 'shipping_taxable')) {
                $table->dropColumn('shipping_taxable');
            }
            if (Schema::hasColumn('ecommerce_tax_rates', 'effective_date')) {
                $table->dropColumn('effective_date');
            }
        });
    }
};
