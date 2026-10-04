<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LoyaltyService;

class ExpireLoyaltyPointsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecommerce:expire-loyalty-points';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan and expire inactive loyalty points past configured expiration threshold';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting loyalty points expiration check...');
        
        $expiredCount = LoyaltyService::expireOldPoints();

        $this->info("Completed: {$expiredCount} points expired successfully.");

        return Command::SUCCESS;
    }
}
