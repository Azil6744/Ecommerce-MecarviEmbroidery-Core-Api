<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('affiliate:release-commissions')->daily();
        $schedule->command('ecommerce:check-expired-gift-cards')->daily();
        $schedule->command('ecommerce:expire-loyalty-points')->daily();
        $schedule->command('ecommerce:award-birthday-loyalty-bonus')->dailyAt('08:00'); // Runs every morning at 8 AM
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
