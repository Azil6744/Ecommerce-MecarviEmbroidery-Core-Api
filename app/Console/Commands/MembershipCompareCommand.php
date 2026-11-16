<?php

namespace App\Console\Commands;

use App\Models\EcommerceMembership;
use App\Models\EcommerceMembershipActivity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan membership:compare customer@example.com
 *
 * Read-only. Shows a customer's membership as Central has it (what the admin panel shows) and as
 * Core's local copy has it (what the customer panel falls back to), plus the plan changes and
 * payments recorded locally, so a mismatch between the two can be seen and corrected.
 */
class MembershipCompareCommand extends Command
{
    protected $signature = 'membership:compare {email : The customer\'s email address}';

    protected $description = 'Compare a customer\'s membership in Central and in Core\'s local copy (read-only)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $this->line('<options=bold>Central (admin panel)</>');
        $url = rtrim((string) config('services.central_auth.url'), '/');
        try {
            $response = $url !== ''
                ? Http::acceptJson()->timeout(20)
                    ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')])
                    ->get($url . '/v1/internal/admin/memberships')
                : null;
        } catch (\Throwable) {
            $response = null;
        }
        if (! $response || ! $response->successful()) {
            $this->error('  Central could not be reached (' . ($response?->status() ?? 'not configured') . ').');
        } else {
            $rows = collect($response->json('data') ?? [])->filter(fn ($m) => strtolower((string) ($m['user']['email'] ?? '')) === $email);
            if ($rows->isEmpty()) {
                $this->line('  none');
            }
            foreach ($rows as $m) {
                $this->line(sprintf(
                    '  #%s  %s  %s %s  status=%s  next=%s  ends=%s  updated=%s',
                    $m['id'] ?? '?',
                    $m['plan_name'] ?? '?',
                    $m['price'] ?? '?',
                    $m['billing_cycle'] ?? '',
                    $m['status'] ?? '?',
                    substr((string) ($m['next_billing_date'] ?? '-'), 0, 10),
                    substr((string) ($m['final_access_at'] ?? '-'), 0, 10),
                    substr((string) ($m['updated_at'] ?? ''), 0, 16)
                ));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Core local copy (customer panel fallback)</>');
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            $this->line('  no store account with this email');

            return self::SUCCESS;
        }
        foreach (EcommerceMembership::where('user_id', $user->id)->get() as $m) {
            $this->line(sprintf(
                '  #%d  %s  %s %s  status=%s  next=%s  cancels=%s  updated=%s',
                $m->id,
                $m->plan_name,
                $m->price,
                $m->billing_cycle,
                $m->status,
                optional($m->next_billing_date)->format('Y-m-d') ?? '-',
                $m->cancels_at ? substr((string) $m->cancels_at, 0, 10) : '-',
                optional($m->updated_at)->format('Y-m-d H:i')
            ));
        }

        $this->newLine();
        $this->line('<options=bold>Plan changes and payments recorded in Core</>');
        $acts = EcommerceMembershipActivity::where('user_id', $user->id)->latest()->limit(20)->get();
        if ($acts->isEmpty()) {
            $this->line('  none');
        }
        foreach ($acts as $a) {
            $this->line(sprintf(
                '  %s  %-22s %s → %s  %s  source=%s',
                optional($a->created_at)->format('Y-m-d H:i'),
                $a->action,
                $a->from_plan ?: '-',
                $a->to_plan ?: '-',
                $a->amount ? '$' . number_format((float) $a->amount, 2) : '',
                $a->source
            ));
        }

        return self::SUCCESS;
    }
}
