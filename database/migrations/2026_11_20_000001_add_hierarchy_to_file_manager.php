<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fm_taxonomies', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('kind')->constrained('fm_taxonomies')->nullOnDelete();
            $table->index('parent_id');
        });

        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('department_id')->constrained('fm_taxonomies')->nullOnDelete();
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('fm_taxonomies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
