<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_colors', function (Blueprint $table) {
            $table->string('hex_code', 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_colors', function (Blueprint $table) {
            $table->string('hex_code', 7)->nullable(false)->default('#000000')->change();
        });
    }
};
