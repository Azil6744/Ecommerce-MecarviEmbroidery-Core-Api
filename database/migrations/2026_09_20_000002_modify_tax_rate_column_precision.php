<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE ecommerce_tax_rates ALTER COLUMN rate TYPE NUMERIC(10,3)");
            \App\Models\TaxRate::where('state', 'New York')->update(['rate' => 8.875]);
        } catch (\Exception $e) {
            // Fallback for MySQL if run against MySQL
            try {
                DB::statement("ALTER TABLE ecommerce_tax_rates MODIFY COLUMN rate DECIMAL(10,3) NOT NULL DEFAULT 0.000");
                \App\Models\TaxRate::where('state', 'New York')->update(['rate' => 8.875]);
            } catch (\Exception $e2) {}
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE ecommerce_tax_rates ALTER COLUMN rate TYPE NUMERIC(10,2)");
        } catch (\Exception $e) {
            try {
                DB::statement("ALTER TABLE ecommerce_tax_rates MODIFY COLUMN rate DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            } catch (\Exception $e2) {}
        }
    }
};
