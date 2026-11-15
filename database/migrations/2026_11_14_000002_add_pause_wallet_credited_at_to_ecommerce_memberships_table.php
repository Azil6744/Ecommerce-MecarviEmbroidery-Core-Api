<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ecommerce_memberships', 'pause_wallet_credited_at')) {
            Schema::table('ecommerce_memberships', function (Blueprint $table) {
                $table->timestamp('pause_wallet_credited_at')->nullable()->after('pause_cycle_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_memberships', 'pause_wallet_credited_at')) {
            Schema::table('ecommerce_memberships', function (Blueprint $table) {
                $table->dropColumn('pause_wallet_credited_at');
            });
        }
    }
};
