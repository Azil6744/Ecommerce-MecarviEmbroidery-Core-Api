<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_page_contents', function (Blueprint $table) {
            if (! Schema::hasColumn('membership_page_contents', 'hero_features')) {
                $table->json('hero_features')->nullable();
            }
            if (! Schema::hasColumn('membership_page_contents', 'why_join')) {
                $table->json('why_join')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('membership_page_contents', function (Blueprint $table) {
            foreach (['hero_features', 'why_join'] as $column) {
                if (Schema::hasColumn('membership_page_contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
