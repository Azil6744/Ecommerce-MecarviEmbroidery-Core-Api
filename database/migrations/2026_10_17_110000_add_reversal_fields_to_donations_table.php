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
        if (Schema::hasTable('donations')) {
            Schema::table('donations', function (Blueprint $table) {
                if (!Schema::hasColumn('donations', 'reversed_by')) {
                    $table->string('reversed_by')->nullable()->after('status');
                }
                if (!Schema::hasColumn('donations', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable()->after('reversed_by');
                }
                if (!Schema::hasColumn('donations', 'refund_reason')) {
                    $table->text('refund_reason')->nullable()->after('reversed_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('donations')) {
            Schema::table('donations', function (Blueprint $table) {
                if (Schema::hasColumn('donations', 'refund_reason')) {
                    $table->dropColumn('refund_reason');
                }
                if (Schema::hasColumn('donations', 'reversed_at')) {
                    $table->dropColumn('reversed_at');
                }
                if (Schema::hasColumn('donations', 'reversed_by')) {
                    $table->dropColumn('reversed_by');
                }
            });
        }
    }
};
