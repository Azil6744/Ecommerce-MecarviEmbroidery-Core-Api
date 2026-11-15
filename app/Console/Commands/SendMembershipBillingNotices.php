<?php

namespace App\Console\Commands;

use App\Services\EmailNotificationService;
use App\Services\MembershipProrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Emails driven by each plan's Pricing & Billing settings (admin plan editor):
 * renewal reminders ("Renewal Reminder Timing"), renewal receipts ("Send receipt for each payment"),
 * failed-payment notices and end-of-membership notices.
 *
 * Central's billing job records what happened in membership metadata; this command sends the email
 * once and marks it sent in Central so it is never repeated. Runs after the Central billing job.
 */
class SendMembershipBillingNotices extends Command
{
    protected $signature = 'ecommerce:send-membership-billing-notices {--dry-run : List what would be sent without sending}';

    protected $description = 'Send membership renewal reminders, receipts and failed-payment notices from plan billing settings.';

    public function handle(EmailNotificationService $mail): int
    {
        $url = rtrim((string) config('services.central_auth.url'), '/');
        if ($url === '') {
            $this->error('services.central_auth.url is not configured.');
            return self::FAILURE;
        }
        $http = Http::acceptJson()->timeout(15)
            ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')]);

        $response = $http->get($url . '/v1/internal/admin/memberships');
        if (! $response->successful()) {
            $this->error('Could not load memberships from Central (' . $response->status() . ').');
            return self::FAILURE;
        }

        $today = MembershipProrationService::today();
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ((array) $response->json('data') as $row) {
            $email = $row['user']['email'] ?? null;
            if (! $email) {
                continue;
            }
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $policy = is_array($meta['billing_policy'] ?? null) ? $meta['billing_policy'] : [];
            $status = strtolower((string) ($row['status'] ?? ''));
            $plan = $policy['billing_display_name'] ?? ($row['plan_name'] ?? 'Membership');
            $currency = $policy['currency'] ?? ($row['currency'] ?? 'USD');
            $base = [
                'customer_name' => $row['user']['name'] ?? $email,
                'customer_email' => $email,
                'membership_plan' => $plan,
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
            ];
            $changes = [];

            // 1. Renewal reminder N days before the renewal date.
            $days = (int) ($policy['renewal_reminder_days'] ?? 0);
            $next = MembershipProrationService::parseDate($row['next_billing_date'] ?? null);
            if ($days > 0 && $next && in_array($status, ['active', 'trialing'], true)
                && ($meta['auto_renewal'] ?? true) !== false && empty($row['cancel_at_period_end'])
                && ($meta['reminder_sent_for'] ?? null) !== $next->toDateString()) {
                $daysLeft = MembershipProrationService::daysBetween($today, $next);
                if ($daysLeft >= 0 && $daysLeft <= $days) {
                    $amount = (float) ($meta['renewal_price'] ?? $row['price'] ?? 0) + (float) ($meta['past_due_amount'] ?? 0);
                    if ($this->send($mail, $dry, 'customer_membership_renewal_reminder', $base + [
                        'renewal_date' => $next->format('M j, Y'),
                        'renewal_amount' => $this->money($amount, $currency),
                    ], $email)) {
                        $changes['reminder_sent_for'] = $next->toDateString();
                    }
                }
            }

            // 2. Receipt for a renewal the billing job just collected.
            $last = is_array($meta['last_renewal'] ?? null) ? $meta['last_renewal'] : null;
            if ($last && empty($last['receipt_sent']) && ($last['receipt_requested'] ?? true)) {
                if ($this->send($mail, $dry, 'customer_membership_subscription_renew', $base + [
                    'next_billing_date' => MembershipProrationService::parseDate($last['period_end'] ?? null)?->format('M j, Y') ?? '',
                    'amount' => $this->money((float) ($last['amount'] ?? 0), $currency),
                ], $email)) {
                    $changes['last_renewal'] = array_merge($last, ['receipt_sent' => true]);
                }
            }

            // 3. Failed renewal payment.
            $failed = is_array($meta['failed_payment'] ?? null) ? $meta['failed_payment'] : null;
            if ($failed && empty($failed['notice_sent'])) {
                $grace = $failed['grace_ends_at'] ?? null;
                $graceNotice = $status === 'suspended'
                    ? 'Your membership has been suspended.'
                    : ($grace ? 'Your benefits stay active until ' . MembershipProrationService::parseDate($grace)?->format('M j, Y') . '.' : 'We will try again soon.');
                if ($this->send($mail, $dry, 'customer_membership_payment_failed', $base + [
                    'amount_due' => $this->money((float) ($failed['amount'] ?? 0), $currency),
                    'grace_notice' => $graceNotice,
                ], $email)) {
                    $changes['failed_payment'] = array_merge($failed, ['notice_sent' => true]);
                }
            }

            // 4. Membership ended (auto-renew off, max duration, trial ended).
            if (array_key_exists('end_notice_sent', $meta) && $meta['end_notice_sent'] === false) {
                if ($this->send($mail, $dry, 'customer_membership_expire', $base + [
                    'expiry_date' => MembershipProrationService::parseDate($row['final_access_at'] ?? null)?->format('M j, Y') ?? $today->format('M j, Y'),
                ], $email)) {
                    $changes['end_notice_sent'] = true;
                }
            }

            if ($changes && ! $dry) {
                $update = $http->post($url . '/v1/internal/admin/memberships/update', [
                    'membership_id' => (int) $row['id'],
                    'actor_type' => 'system',
                    'metadata' => array_merge($meta, $changes),
                    'admin_authorization_reason' => 'Billing notice sent: ' . implode(', ', array_keys($changes)),
                ]);
                if (! $update->successful()) {
                    Log::warning("Membership {$row['id']}: notice sent but not marked in Central ({$update->status()}).");
                }
            }
            $sent += count($changes);
        }

        $this->info(($dry ? 'Would send ' : 'Sent ') . "{$sent} membership notice(s).");

        return self::SUCCESS;
    }

    private function send(EmailNotificationService $mail, bool $dry, string $event, array $data, string $email): bool
    {
        if ($dry) {
            $this->line("[dry-run] {$event} → {$email}");
            return true;
        }
        try {
            $mail->sendEvent($event, $data, $email);

            // Matching in-app notice (de-duplicated, so re-runs never repeat it).
            $inApp = [
                'customer_membership_renewal_reminder' => 'renewal_reminder',
                'customer_membership_subscription_renew' => 'renewal_paid',
                'customer_membership_payment_failed' => 'payment_failed',
                'customer_membership_expire' => 'expired',
            ][$event] ?? null;
            if ($inApp) {
                \App\Services\MembershipNotifier::notify($email, $inApp, [
                    'plan_name' => $data['membership_plan'] ?? null,
                    'date' => $data['renewal_date'] ?? $data['next_billing_date'] ?? $data['expiry_date'] ?? null,
                    'amount' => isset($data['amount']) ? (float) preg_replace('/[^0-9.]/', '', (string) $data['amount']) : null,
                ], 'notice-' . md5($event . '|' . strtolower($email) . '|' . json_encode($data)));
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("Membership notice {$event} to {$email} failed: " . $e->getMessage());
            return false;
        }
    }

    private function money(float $amount, string $currency): string
    {
        $symbol = ['USD' => '$', 'CAD' => '$', 'AUD' => '$', 'EUR' => '€', 'GBP' => '£'][strtoupper($currency)] ?? '';
        return $symbol . number_format($amount, 2) . ($symbol === '' ? ' ' . $currency : '');
    }
}
