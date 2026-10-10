<?php

namespace App\Console\Commands;

use App\Services\AffiliatePayoutService;
use Illuminate\Console\Command;

class ProcessScheduledAffiliatePayouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affiliate:process-payouts';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Process approved affiliate payouts that are scheduled for today or earlier.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting processing of scheduled affiliate payouts...');

        $processed = AffiliatePayoutService::processScheduledPayouts();

        $this->info("Processed {$processed} scheduled affiliate payout(s).");

        return 0;
    }
}
