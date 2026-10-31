<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database-level protection against double payment / duplicate referrals.
     * Indexes are only added when existing data has no duplicates (otherwise the duplicates
     * are logged and must be cleaned up first), so this migration can never fail a deploy.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_referral_commissions')) {
            $dupes = DB::table('ecommerce_referral_commissions')
                ->select('source_type', 'source_ref')
                ->whereNotNull('source_ref')
                ->groupBy('source_type', 'source_ref')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($dupes === 0) {
                Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
                    $table->unique(['source_type', 'source_ref'], 'erc_source_unique');
                });
            } else {
                Log::warning("Affiliate: {$dupes} duplicate (source_type, source_ref) commission groups found; unique index skipped. Clean up and re-run.");
            }
        }

        if (Schema::hasTable('ecommerce_referrals')) {
            $dupes = DB::table('ecommerce_referrals')
                ->select('referrer_id', 'referred_id')
                ->whereNotNull('referred_id')
                ->groupBy('referrer_id', 'referred_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($dupes === 0) {
                Schema::table('ecommerce_referrals', function (Blueprint $table) {
                    $table->unique(['referrer_id', 'referred_id'], 'er_referrer_referred_unique');
                });
            } else {
                Log::warning("Affiliate: {$dupes} duplicate (referrer_id, referred_id) referral groups found; unique index skipped. Clean up and re-run.");
            }
        }
    }

    public function down(): void
    {
        Schema::table('ecommerce_referral_commissions', function (Blueprint $table) {
            try { $table->dropUnique('erc_source_unique'); } catch (\Throwable $e) {}
        });
        Schema::table('ecommerce_referrals', function (Blueprint $table) {
            try { $table->dropUnique('er_referrer_referred_unique'); } catch (\Throwable $e) {}
        });
    }
};
