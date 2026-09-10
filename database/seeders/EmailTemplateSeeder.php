<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\EmailNotificationService;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Seed all 49 default email templates into the database.
     */
    public function run(): void
    {
        $service = app(EmailNotificationService::class);
        $service->ensureDefaultTemplates();

        $count = count(EmailNotificationService::EVENTS);
        $this->command->info("✓ All {$count} email templates seeded successfully.");
    }
}
