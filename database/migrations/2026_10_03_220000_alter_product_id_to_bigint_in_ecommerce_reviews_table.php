<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_reviews') && Schema::hasColumn('ecommerce_reviews', 'product_id')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE ecommerce_reviews ALTER COLUMN product_id TYPE bigint USING product_id::bigint');
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE ecommerce_reviews MODIFY product_id BIGINT UNSIGNED NOT NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ecommerce_reviews') && Schema::hasColumn('ecommerce_reviews', 'product_id')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE ecommerce_reviews ALTER COLUMN product_id TYPE varchar(255) USING product_id::text');
            }
        }
    }
};
