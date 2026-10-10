<?php

namespace App\Console\Commands;

use App\Models\EcommerceReferralCommission;
use App\Services\AffiliateCommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleasePendingCommissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affiliate:release-commissions';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Credit admin-approved referral commissions to referrers\' wallets once their holding period has passed. Pending (unapproved) commissions are never released automatically.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting release of approved commissions...');

        $commissions = EcommerceReferralCommission::where('status', 'approved')
            ->where('payout_at', '<=', now())
            ->get();

        if ($commissions->isEmpty()) {
            $this->info('No approved commissions are due for release.');
            return 0;
        }

        $releasedCount = 0;

        foreach ($commissions as $commission) {
            try {
                if (AffiliateCommissionService::credit($commission)) {
                    $releasedCount++;
                }
            } catch (\Throwable $e) {
                $this->error('Failed to release commission ID ' . $commission->id . ': ' . $e->getMessage());
                Log::error('Commission release error for ID ' . $commission->id . ': ' . $e->getMessage());
            }
        }

        $this->info('Successfully released ' . $releasedCount . ' referral commissions.');
        return 0;
    }
}
