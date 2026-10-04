<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'product_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('product_type', 20)->default('physical')->after('is_digital')->index();
            });
        }

        // Backfill from the legacy flag / attributes JSON.
        foreach (DB::table('products')->select('id', 'is_digital', 'attributes')->get() as $row) {
            $attrs = is_string($row->attributes) ? json_decode($row->attributes, true) : [];
            $type = $attrs['product_type'] ?? null;
            if (! in_array($type, ['physical', 'digital', 'quotation'], true)) {
                $type = $row->is_digital ? 'digital' : 'physical';
            }
            DB::table('products')->where('id', $row->id)->update(['product_type' => $type]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'product_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('product_type');
            });
        }
    }
};
