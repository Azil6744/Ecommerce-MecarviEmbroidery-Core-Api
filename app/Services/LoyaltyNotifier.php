<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sends loyalty e-mails through the existing EmailNotificationService templates.
 * Never throws: loyalty ledger operations must not fail because mail failed.
 */
class LoyaltyNotifier
{
    protected static function reference(?string $ref): string
    {
        if (!$ref) {
            return 'rewards activity';
        }
        $ref = ltrim($ref, '#');

        return preg_match('/^[A-Za-z]{1,4}[-]?\d+/', $ref) ? 'Order #' . $ref : $ref;
    }

    public static function send(User $user, string $event, array $data = []): void
    {
        try {
            if (!$user->email) {
                return;
            }
            $payload = array_merge([
                'customer_name' => $user->name ?: 'Customer',
                'customer_email' => $user->email,
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
                'reward_reference' => self::reference($data['order_number'] ?? null),
            ], $data);

            app(EmailNotificationService::class)->sendEvent($event, $payload, $user->email);
        } catch (\Throwable $e) {
            Log::warning("LoyaltyNotifier ({$event}) failed: " . $e->getMessage());
        }
    }
}
