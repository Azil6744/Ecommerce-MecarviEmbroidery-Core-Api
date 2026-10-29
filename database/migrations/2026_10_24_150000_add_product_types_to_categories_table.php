<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'product_types')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->json('product_types')->nullable()->after('is_active');
            });
        }

        // Backfill existing categories:
        // Digital Designs (id 8) and its subcategories -> ['digital']
        // All others -> ['physical', 'quotation']
        $digitalIds = DB::table('categories')
            ->where('id', 8)
            ->orWhere('parent_id', 8)
            ->pluck('id');

        if ($digitalIds->isNotEmpty()) {
            DB::table('categories')
                ->whereIn('id', $digitalIds)
                ->update(['product_types' => json_encode(['digital'])]);
        }

        DB::table('categories')
            ->whereNotIn('id', $digitalIds)
            ->whereNull('product_types')
            ->update(['product_types' => json_encode(['physical', 'quotation'])]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'product_types')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('product_types');
            });
        }
    }
};
