<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

/**
 * In-app (and, for urgent events, SMS) notifications for membership lifecycle events.
 *
 * Called from the membership activity observer (customer + admin actions recorded
 * locally), from admin membership endpoints, the Stripe webhook and the billing
 * notices job. Never throws: a notification failure must not break the action.
 */
class MembershipNotifier
{
    /** Events important enough to also text the member (when SMS is configured). */
    private const SMS_EVENTS = ['payment_failed', 'cancel', 'admin_cancelled', 'deactivated', 'expiring_soon', 'expired', 'banned', 'refund'];

    public static function notify(User|int|string|null $who, string $event, array $ctx = [], ?string $dedupeKey = null): void
    {
        try {
            $user = self::resolveUser($who);
            if (! $user) {
                return;
            }

            [$title, $body] = self::message($event, $ctx);
            if ($title === null) {
                return; // not a customer-facing event
            }

            $attributes = [
                'category' => 'memberships',
                'type' => 'membership.' . $event,
                'title' => $title,
                'body' => $body,
                'link' => '/memberships',
                'data' => array_filter($ctx, fn ($v) => is_scalar($v) || $v === null),
            ];

            if ($dedupeKey) {
                $notification = UserNotification::firstOrCreate(
                    ['user_id' => $user->id, 'dedupe_key' => $dedupeKey],
                    $attributes
                );
                if (! $notification->wasRecentlyCreated) {
                    return; // already notified about this exact event
                }
            } else {
                UserNotification::create(['user_id' => $user->id] + $attributes);
            }

            if (in_array($event, self::SMS_EVENTS, true) && filled($user->phone) && self::smsAllowed($user)) {
                $error = null;
                app(SmsService::class)->sendSms((string) $user->phone, $title . ': ' . $body, $error);
                if ($error) {
                    Log::info('Membership SMS not sent: ' . $error);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Membership notification '{$event}' failed: " . $e->getMessage());
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function message(string $event, array $ctx): array
    {
        $plan = $ctx['to_plan'] ?? $ctx['plan_name'] ?? $ctx['from_plan'] ?? 'your plan';
        $from = $ctx['from_plan'] ?? null;
        $date = isset($ctx['date']) ? self::fmtDate($ctx['date']) : null;
        $amount = isset($ctx['amount']) && (float) $ctx['amount'] > 0 ? '$' . number_format((float) $ctx['amount'], 2) : null;

        return match ($event) {
            'upgrade' => ['Membership upgraded', "You're now on {$plan}." . ($amount ? " We charged {$amount} for the rest of this billing period." : '')],
            'downgrade' => ['Membership changed', "You're now on {$plan}." . ($amount ? " {$amount} was credited for the unused time." : '')],
            'lateral', 'change_plan', 'plan_changed' => ['Membership plan changed', "Your membership is now {$plan}" . ($from ? " (was {$from})." : '.')],
            'downgrade_scheduled' => ['Plan change scheduled', "You'll move to {$plan}" . ($date ? " on {$date}" : ' at the end of this billing period') . '.'],
            'scheduled_change_cancelled' => ['Scheduled change cancelled', 'Your membership will stay on its current plan.'],
            'subscription_confirmed' => ['Membership confirmed', "Your {$plan} membership is active." . ($amount ? " Paid {$amount}." : '')],
            'pause', 'admin_paused' => ['Membership paused', 'Your membership is paused' . ($date ? " until {$date}" : '') . '. Benefits resume when it restarts.'],
            'resume', 'admin_resumed' => ['Membership resumed', "Your {$plan} benefits are active again."],
            'cancel', 'admin_cancelled' => ['Membership cancelled', 'Your membership is cancelled.' . ($date ? " You keep your benefits until {$date}." : '')],
            'reactivate' => ['Membership reactivated', "Welcome back! Your {$plan} membership is active."],
            'auto_renew_on' => ['Auto-renewal turned on', "Your {$plan} membership will renew automatically."],
            'auto_renew_off' => ['Auto-renewal turned off', 'Your membership will not renew' . ($date ? " after {$date}" : '') . '.'],
            'deactivated' => ['Membership deactivated', 'Your membership has been deactivated.' . ($date ? " Effective {$date}." : '')],
            'deactivation_scheduled' => ['Deactivation scheduled', 'Your membership will be deactivated' . ($date ? " on {$date}" : '') . '.'],
            'banned' => ['Membership suspended', 'Your membership has been suspended. Please contact support.'],
            'unbanned' => ['Membership restored', 'Your membership is active again.'],
            'renewal_paid' => ['Membership renewed', "Your {$plan} membership renewed" . ($amount ? " for {$amount}" : '') . ($date ? ". Next billing date: {$date}." : '.')],
            'renewal_reminder' => ['Renewal coming up', "Your {$plan} membership renews" . ($date ? " on {$date}" : ' soon') . ($amount ? " for {$amount}." : '.')],
            'payment_failed' => ['Membership payment failed', "We couldn't charge your payment method for {$plan}. Please update it to keep your benefits."],
            'expiring_soon' => ['Membership ending soon', "Your {$plan} membership ends" . ($date ? " on {$date}" : ' soon') . '. Turn on auto-renewal to keep your benefits.'],
            'expired' => ['Membership ended', "Your {$plan} membership has ended."],
            'refund' => ['Refund issued', ($amount ? "{$amount} was refunded" : 'A refund was issued') . " for your {$plan} membership."],
            default => [null, null],
        };
    }

    private static function resolveUser(User|int|string|null $who): ?User
    {
        if ($who instanceof User) {
            return $who;
        }
        if (is_int($who) || (is_string($who) && ctype_digit($who))) {
            return User::find((int) $who);
        }
        if (is_string($who) && str_contains($who, '@')) {
            return User::whereRaw('LOWER(email) = ?', [strtolower($who)])->first();
        }

        return null;
    }

    /** Honour the member's "Memberships → SMS" preference when one is stored. */
    private static function smsAllowed(User $user): bool
    {
        $prefs = $user->notification_preferences ?? null;
        if (is_string($prefs)) {
            $prefs = json_decode($prefs, true);
        }
        if (is_array($prefs) && isset($prefs['memberships']['sms'])) {
            return (bool) $prefs['memberships']['sms'];
        }

        return true;
    }

    private static function fmtDate(mixed $value): ?string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
