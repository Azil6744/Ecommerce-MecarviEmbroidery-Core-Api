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
        Schema::table('charities', function (Blueprint $table) {
            if (!Schema::hasColumn('charities', 'image')) {
                $table->string('image')->nullable()->after('logo_svg_type');
            }
            if (!Schema::hasColumn('charities', 'banner_image')) {
                $table->string('banner_image')->nullable()->after('image');
            }
            if (!Schema::hasColumn('charities', 'banner_script')) {
                $table->string('banner_script')->nullable()->after('banner_image');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charities', function (Blueprint $table) {
            $table->dropColumn(['image', 'banner_image', 'banner_script']);
        });
    }
};
