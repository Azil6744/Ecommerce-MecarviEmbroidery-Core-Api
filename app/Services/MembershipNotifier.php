<?php

namespace App\Services;

use App\Models\EmailNotificationLog;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * In-app, email (and, for urgent events, SMS) notifications for membership lifecycle events.
 *
 * Called from the membership activity observer (customer + admin actions recorded
 * locally), from admin membership endpoints, the Stripe webhook and the billing
 * notices job. Never throws: a notification failure must not break the action.
 */
class MembershipNotifier
{
    /** Events important enough to also text the member (when SMS is configured). */
    private const SMS_EVENTS = ['payment_failed', 'cancel', 'admin_cancelled', 'deactivated', 'expiring_soon', 'expired', 'banned', 'refund'];

    /**
     * Email template (EmailNotificationService event key) for each membership event.
     *
     * Not listed on purpose, because the code that performs the action already sends a
     * richer email itself: pause / resume / cancel (admin and customer) and renewal_reminder.
     */
    private const EMAIL_EVENTS = [
        'upgrade' => 'customer_membership_upgraded',
        'downgrade' => 'customer_membership_downgraded',
        'lateral' => 'customer_membership_plan_changed',
        'change_plan' => 'customer_membership_plan_changed',
        'plan_changed' => 'customer_membership_plan_changed',
        'downgrade_scheduled' => 'customer_membership_change_scheduled',
        'scheduled_change_cancelled' => 'customer_membership_scheduled_change_cancelled',
        'subscription_confirmed' => 'customer_membership_subscription',
        'reactivate' => 'customer_membership_reactivated',
        'auto_renew_on' => 'customer_membership_auto_renew_on',
        'auto_renew_off' => 'customer_membership_auto_renew_off',
        'deactivated' => 'customer_membership_deactivated',
        'deactivation_scheduled' => 'customer_membership_deactivation_scheduled',
        'banned' => 'customer_membership_suspended',
        'unbanned' => 'customer_membership_restored',
        'renewal_paid' => 'customer_membership_subscription_renew',
        'payment_failed' => 'customer_membership_payment_failed',
        'expiring_soon' => 'customer_membership_expire',
        'expired' => 'customer_membership_ended',
        'cancelled' => 'customer_membership_ended',
        'refund' => 'customer_membership_refund',
        // Applied by Central's nightly jobs on the effective date (sent by the billing notices command).
        'pause_started' => 'customer_membership_pause_started',
        'pause_ended' => 'customer_membership_resumed',
        'confirmation_required' => 'customer_membership_confirmation_required',
    ];

    /**
     * @param  array  $ctx  Event details. Pass 'email' => false when the caller has already
     *                      emailed the member about this event.
     */
    public static function notify(User|int|string|null $who, string $event, array $ctx = [], ?string $dedupeKey = null): void
    {
        try {
            $sendEmail = ($ctx['email'] ?? true) !== false;
            unset($ctx['email']);

            $user = self::resolveUser($who);
            // Central members may have no local user row: they still get the email.
            $address = $user?->email ?: (is_string($who) && str_contains($who, '@') ? trim($who) : null);
            if (! $user && ! $address) {
                return;
            }

            [$title, $body] = self::message($event, $ctx);
            if ($title === null) {
                return; // not a customer-facing event
            }

            if ($user) {
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
            } elseif ($dedupeKey && ! Cache::add('membership-notice:' . md5($dedupeKey . '|' . strtolower($address)), 1, now()->addDays(7))) {
                return; // already notified about this exact event
            }

            if ($sendEmail && $address) {
                self::email($user, $address, $event, $ctx, $title, $body);
            }
        } catch (\Throwable $e) {
            Log::warning("Membership notification '{$event}' failed: " . $e->getMessage());
        }
    }

    /** Email the member about the event through the admin-editable email templates. */
    private static function email(?User $user, string $address, string $event, array $ctx, string $title, string $body): void
    {
        $eventKey = self::EMAIL_EVENTS[$event] ?? null;
        if ($eventKey === null) {
            return;
        }

        try {
            // One action can reach here twice (activity log + the endpoint's own notice), or after
            // the endpoint already sent this template itself: never email the same thing twice.
            $recent = EmailNotificationLog::where('event_key', $eventKey)
                ->whereRaw('LOWER(recipient_email) = ?', [strtolower($address)])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->exists();
            if ($recent) {
                return;
            }

            $date = isset($ctx['date']) ? self::fmtDate($ctx['date']) : null;
            if ($event === 'expiring_soon' && ! $date) {
                return; // that template is only meaningful with the end date
            }
            $amount = isset($ctx['amount']) && (float) $ctx['amount'] > 0 ? '$' . number_format((float) $ctx['amount'], 2) : null;
            $plan = $ctx['to_plan'] ?? $ctx['plan_name'] ?? $ctx['from_plan'] ?? 'Membership';

            app(EmailNotificationService::class)->sendEvent($eventKey, [
                'customer_name' => $user?->name ?: ($ctx['customer_name'] ?? 'Member'),
                'customer_email' => $address,
                'membership_plan' => $plan,
                'previous_plan' => $ctx['from_plan'] ?? '',
                'new_plan' => $ctx['to_plan'] ?? $plan,
                'effective_date' => $date ?? '',
                'amount' => $amount ?? '',
                'update_title' => $title,
                'update_message' => $body,
                // Variables of the older templates reused above.
                'next_billing_date' => $date ?? 'your next billing date',
                'expiry_date' => $date ?? '',
                'amount_due' => $amount ?? 'the amount due',
                'grace_notice' => $ctx['grace_notice'] ?? 'We will try again soon.',
                'refund_amount' => $amount ?? '',
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
            ], $address);
        } catch (\Throwable $e) {
            Log::warning("Membership email '{$event}' to {$address} failed: " . $e->getMessage());
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
            'pause_started' => ['Membership pause started', "Your {$plan} membership is now paused" . ($date ? " until {$date}" : '') . '. Benefits resume when it restarts.'],
            'pause_ended' => ['Membership resumed', "Your pause has ended and your {$plan} benefits are active again."],
            'confirmation_required' => ['Confirm your membership', "Your {$plan} trial has ended. Confirm your subscription" . ($amount ? " ({$amount})" : '') . ' on your membership page to keep your benefits.'],
            'resume', 'admin_resumed' =>['Membership resumed', "Your {$plan} benefits are active again."],
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
            'expired', 'cancelled' => ['Membership ended', "Your {$plan} membership has ended."],
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
