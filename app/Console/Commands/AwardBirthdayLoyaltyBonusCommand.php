<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LoyaltyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AwardBirthdayLoyaltyBonusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ecommerce:award-birthday-loyalty-bonus';

    /**
     * The console command description.
     */
    protected $description = 'Award loyalty birthday bonus points to customers whose birthday is today';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $settings = LoyaltyService::getSettings();

        if (!$settings['enabled']) {
            $this->info('Loyalty program is disabled. Skipping birthday bonus awards.');
            return Command::SUCCESS;
        }

        $custAct = $settings['activities']['customer']['birthday'] ?? ['enabled' => false, 'points' => 0];
        $bizAct  = $settings['activities']['business']['birthday'] ?? ['enabled' => false, 'points' => 0];
        if ((!$custAct['enabled'] || $custAct['points'] <= 0) && (!$bizAct['enabled'] || $bizAct['points'] <= 0)) {
            $this->info('Birthday bonus is disabled or set to 0. Skipping.');
            return Command::SUCCESS;
        }

        $today = Carbon::now();
        $month = $today->month;
        $day   = $today->day;
        $year  = $today->year;

        $this->info("Checking for birthdays on {$today->format('M d')}...");

        // Find users whose date_of_birth (or birthday) column matches today (month + day)
        // Supports both 'date_of_birth' and 'birthday' column names via fallback
        $birthdayUsers = collect();

        try {
            // PostgreSQL: extract month/day from dob column
            $birthdayUsers = User::whereNotNull('dob')
                ->whereRaw('EXTRACT(MONTH FROM dob) = ?', [$month])
                ->whereRaw('EXTRACT(DAY FROM dob) = ?', [$day])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('AwardBirthdayLoyaltyBonus: dob column query failed: ' . $e->getMessage());
        }

        if ($birthdayUsers->isEmpty()) {
            $this->info('No customers have a birthday today.');
            return Command::SUCCESS;
        }

        $awarded = 0;
        $skipped = 0;

        foreach ($birthdayUsers as $user) {
            $userAct = LoyaltyService::activity('birthday', $user);
            $pts = (int) ($userAct['points'] ?? 0);
            if (!$userAct['enabled'] || $pts <= 0) {
                $skipped++;
                continue;
            }

            // Reference ID includes year so bonus only fires once per year per user
            $refId = "{$year}-{$user->id}";

            $result = LoyaltyService::awardActivity(
                $user->id,
                'birthday',
                'birthday_bonus',
                "Happy Birthday! Here are {$pts} bonus points from Mecarvi Embroidery.",
                'birthday',
                $refId
            );

            if ($result) {
                $awarded++;
                $this->line("  ✓ Awarded {$pts} pts to {$user->name} ({$user->email})");
            } else {
                $skipped++;
                $this->line("  — Skipped {$user->name} (already awarded this year or error)");
            }
        }

        $this->info("Birthday bonus run complete: {$awarded} awarded, {$skipped} skipped.");
        return Command::SUCCESS;
    }
}
