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
        if (Schema::hasTable('donations') && !Schema::hasColumn('donations', 'note')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->text('note')->nullable()->after('payment_method_email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('donations') && Schema::hasColumn('donations', 'note')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
