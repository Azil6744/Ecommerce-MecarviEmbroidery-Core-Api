<?php

namespace App\Services;

use App\Models\EcommerceMembershipActivity;
use App\Models\MembershipTrialClaim;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One free trial per person, not per account.
 *
 * A trial is refused when an earlier trial on another account used the same card, the same
 * device, the same phone number or the same email (ignoring +tags and Gmail dots). A matching
 * IP address alone does not refuse a trial (offices, families and mobile networks share IPs);
 * it flags the membership for an admin to review instead.
 */
class MembershipTrialGuard
{
    public const IP_REVIEW_DAYS = 30;

    private const MESSAGES = [
        'card' => 'A free trial has already been used with this card.',
        'device' => 'A free trial has already been used on this device.',
        'phone' => 'A free trial has already been used with this phone number.',
        'email' => 'A free trial has already been used with this email address.',
    ];

    /**
     * What identifies the person asking for a trial.
     *
     * @param array|null $card the card the purchase uses (MembershipCardService::resolveForPurchase)
     */
    public function signals(Request $request, User $user, ?array $card): array
    {
        $centralUser = (array) $request->attributes->get('central_auth_user', []);
        $phone = (string) ($centralUser['phone'] ?? '') ?: (string) ($user->phone ?? '');
        $device = trim((string) $request->header('X-Device-Id', ''));

        return [
            'email_normalized' => self::normalizeEmail((string) $user->email) ?: null,
            'phone_normalized' => self::normalizePhone($phone) ?: null,
            'card_fingerprint' => $card['fingerprint'] ?? null,
            // Only a real browser id counts; without one the device check is skipped.
            'device_hash' => strlen($device) >= 16 && strlen($device) <= 200 ? hash('sha256', $device) : null,
            'ip_address' => $request->ip(),
        ];
    }

    /**
     * An earlier trial on a different account that shares a hard signal.
     *
     * @return array{rule: string, message: string, claim: MembershipTrialClaim}|null
     */
    public function findRepeat(User $user, array $signals): ?array
    {
        foreach (['card' => 'card_fingerprint', 'device' => 'device_hash', 'phone' => 'phone_normalized', 'email' => 'email_normalized'] as $rule => $column) {
            if (empty($signals[$column])) {
                continue;
            }
            $claim = MembershipTrialClaim::where('status', 'granted')
                ->where($column, $signals[$column])
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->oldest()
                ->first();
            if ($claim) {
                return ['rule' => $rule, 'message' => self::MESSAGES[$rule], 'claim' => $claim];
            }
        }

        return null;
    }

    /** Another account got a trial from this IP recently: allowed, but worth a look. */
    public function ipNeedsReview(User $user, array $signals): bool
    {
        if (empty($signals['ip_address'])) {
            return false;
        }

        return MembershipTrialClaim::where('status', 'granted')
            ->where('ip_address', $signals['ip_address'])
            ->where('user_id', '!=', $user->id)
            ->where('created_at', '>=', now()->subDays(self::IP_REVIEW_DAYS))
            ->exists();
    }

    public function recordRefused(User $user, ?int $planId, array $signals, array $repeat): void
    {
        try {
            MembershipTrialClaim::create($signals + [
                'user_id' => $user->id,
                'plan_id' => $planId,
                'status' => 'refused',
                'matched_rule' => $repeat['rule'],
                'matched_claim_id' => $repeat['claim']->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Trial claim (refused) not recorded: ' . $e->getMessage());
        }
        Log::info('Free trial refused', ['user_id' => $user->id, 'plan_id' => $planId, 'rule' => $repeat['rule'], 'earlier_claim' => $repeat['claim']->id, 'earlier_user' => $repeat['claim']->user_id]);
    }

    public function recordGranted(User $user, ?int $planId, array $signals, bool $ipFlagged): ?MembershipTrialClaim
    {
        try {
            return MembershipTrialClaim::create($signals + [
                'user_id' => $user->id,
                'plan_id' => $planId,
                'status' => 'granted',
                'ip_flagged' => $ipFlagged,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Trial claim (granted) not recorded: ' . $e->getMessage());

            return null;
        }
    }

    /** Link the claim to the membership Central created, and note an IP match in its activity log. */
    public function attachMembership(?MembershipTrialClaim $claim, ?int $centralMembershipId, Request $request, string $planName): void
    {
        if (! $claim) {
            return;
        }
        try {
            if ($centralMembershipId) {
                $claim->update(['central_membership_id' => $centralMembershipId]);
            }
            if ($claim->ip_flagged) {
                EcommerceMembershipActivity::create([
                    'membership_id' => null,
                    'user_id' => $claim->user_id,
                    'source' => 'central',
                    'action' => 'trial_review',
                    'to_plan' => $planName,
                    'performed_by' => 'system',
                    'actor_name' => 'Trial check',
                    'ip_address' => $request->ip(),
                    'details' => [
                        'central_membership_id' => $centralMembershipId,
                        'reason' => 'Another account started a free trial from the same IP address in the last ' . self::IP_REVIEW_DAYS . ' days.',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Trial claim link failed: ' . $e->getMessage());
        }
    }

    /** Lower-case; drop "+tag"; for Gmail also drop dots (Gmail ignores them). */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /** Digits only; the last 10 so "+1 470…" and "470…" match. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
