<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('variable_settings') && Schema::hasColumn('variable_settings', 'icon')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE variable_settings ALTER COLUMN icon TYPE TEXT');
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE variable_settings MODIFY icon LONGTEXT NULL');
            } else {
                Schema::table('variable_settings', function (Blueprint $table) {
                    $table->longText('icon')->nullable()->change();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('variable_settings') && Schema::hasColumn('variable_settings', 'icon')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE variable_settings ALTER COLUMN icon TYPE VARCHAR(255)');
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE variable_settings MODIFY icon VARCHAR(255) NULL');
            } else {
                Schema::table('variable_settings', function (Blueprint $table) {
                    $table->string('icon', 255)->nullable()->change();
                });
            }
        }
    }
};
