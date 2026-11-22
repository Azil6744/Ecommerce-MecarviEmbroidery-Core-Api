<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate earnings become their own balance: the affiliate either converts them into the wallet
 * or withdraws them to the bank. converted_to_wallet tracks what has been moved into the wallet.
 *
 * Before this change approved commissions were credited straight into the wallet, so for existing
 * affiliates every uncommitted dollar of earnings is already sitting in the wallet: record it as
 * converted so it is not counted twice (once in the wallet, once as withdrawable earnings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ecommerce_affiliates', 'converted_to_wallet')) {
            Schema::table('ecommerce_affiliates', function (Blueprint $table) {
                $table->decimal('converted_to_wallet', 12, 2)->default(0)->after('total_earnings');
            });
        }

        DB::table('ecommerce_affiliates')->orderBy('id')->each(function ($a) {
            $committed = (float) DB::table('ecommerce_affiliate_payouts')
                ->where('user_id', $a->user_id)
                ->whereIn('status', ['pending', 'approved', 'paid'])
                ->selectRaw('COALESCE(SUM(amount), 0) - COALESCE(SUM(reversed_amount), 0) as net')
                ->value('net');
            $alreadyInWallet = max(0, round((float) $a->total_earnings - $committed, 2));
            if ($alreadyInWallet > 0) {
                DB::table('ecommerce_affiliates')->where('id', $a->id)->update(['converted_to_wallet' => $alreadyInWallet]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_affiliates', 'converted_to_wallet')) {
            Schema::table('ecommerce_affiliates', function (Blueprint $table) {
                $table->dropColumn('converted_to_wallet');
            });
        }
    }
};
