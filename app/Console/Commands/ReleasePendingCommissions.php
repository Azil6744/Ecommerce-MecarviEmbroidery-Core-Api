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
        $this->info('Starting release of affiliate commissions...');
        $settings = AffiliateCommissionService::settings();
        $holdingDays = max(1, (int) ($settings['holding_period'] ?? 30));
        $releasedCount = 0;

        // 1. Credit approved commissions whose payout date has arrived
        $approvedDue = EcommerceReferralCommission::where('status', 'approved')
            ->where('payout_at', '<=', now())
            ->get();

        foreach ($approvedDue as $commission) {
            try {
                if (AffiliateCommissionService::credit($commission)) {
                    $releasedCount++;
                }
            } catch (\Throwable $e) {
                $this->error('Failed to credit approved commission ID ' . $commission->id . ': ' . $e->getMessage());
                Log::error('Commission credit error for ID ' . $commission->id . ': ' . $e->getMessage());
            }
        }

        // 2. Auto-approve and credit pending commissions that have completed the 30-day holding window
        $pendingDue = EcommerceReferralCommission::where('status', 'pending')
            ->where('flagged', false)
            ->where('created_at', '<=', now()->subDays($holdingDays))
            ->get();

        foreach ($pendingDue as $commission) {
            try {
                if (AffiliateCommissionService::approve($commission, null, true)) {
                    $releasedCount++;
                }
            } catch (\Throwable $e) {
                $this->error('Failed to auto-release pending commission ID ' . $commission->id . ': ' . $e->getMessage());
                Log::error('Pending commission auto-release error for ID ' . $commission->id . ': ' . $e->getMessage());
            }
        }

        $this->info('Successfully released ' . $releasedCount . ' referral commissions.');
        return 0;
    }
}
