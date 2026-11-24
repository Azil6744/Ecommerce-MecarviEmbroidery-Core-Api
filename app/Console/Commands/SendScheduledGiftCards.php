<?php

namespace App\Console\Commands;

use App\Models\EcommerceGiftCard;
use App\Support\GiftCardMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers admin-issued e-gift cards whose send date has arrived. Cards issued
 * with "Schedule for a later date" are stored with status `scheduled`.
 */
class SendScheduledGiftCards extends Command
{
    protected $signature = 'ecommerce:send-scheduled-gift-cards';

    protected $description = 'Emails scheduled e-gift cards whose send date has arrived.';

    public function handle(): int
    {
        $due = EcommerceGiftCard::query()
            ->whereRaw('LOWER(status) = ?', ['scheduled'])
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->get();

        foreach ($due as $card) {
            try {
                DB::transaction(function () use ($card) {
                    $locked = EcommerceGiftCard::whereKey($card->id)->lockForUpdate()->first();
                    if (! $locked || strtolower((string) $locked->status) !== 'scheduled') {
                        return;
                    }

                    $sent = $locked->recipient_email
                        ? GiftCardMailer::sendIssued($locked->recipient_email, [
                            'code' => $locked->code,
                            'balance' => $locked->current_balance,
                            'message' => $locked->message,
                            'recipient_name' => $locked->recipient_name,
                            'sender_name' => $locked->sender_name,
                            'expires_at' => $locked->expires_at?->toDateString() ?? '',
                            'design_theme' => $locked->design_theme,
                        ])
                        : false;

                    $locked->update(['status' => $sent ? 'delivered' : 'active']);
                    $locked->activityLogs()->create([
                        'action' => $sent ? 'Scheduled Delivery Sent' : 'Scheduled Delivery Failed',
                        'old_value' => 'scheduled',
                        'new_value' => $sent ? 'delivered' : 'active',
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('SendScheduledGiftCards: card '.$card->id.' failed: '.$e->getMessage());
            }
        }

        $this->info("Processed {$due->count()} scheduled gift card(s).");

        return self::SUCCESS;
    }
}
