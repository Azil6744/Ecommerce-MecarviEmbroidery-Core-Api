<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('tone');
        });
    }

    public function down(): void
    {
        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
