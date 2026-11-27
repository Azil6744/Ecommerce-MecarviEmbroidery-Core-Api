<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the admin Customer Management / Business Management directory:
 * table rows (account #, address, orders, wallet / gift card / voucher balances,
 * activity, status) plus the summary tiles and the right-hand panels
 * (verification overview, account alerts, risk & security).
 */
class AdminCustomerDirectoryService
{
    public const BUSINESS_ROLES = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];

    /** Failed sign-ins inside the window that trip the "Failed Login Threshold" flag. */
    public const FAILED_LOGIN_THRESHOLD = 3;
    public const FAILED_LOGIN_WINDOW_DAYS = 7;
    /** Distinct IPs inside the window that count as "Unusual Login Activity". */
    public const UNUSUAL_IP_THRESHOLD = 3;
    public const CARD_EXPIRY_WARNING_DAYS = 60;

    public const FLAGS = [
        'flagged', 'failed_login', 'unusual_login', 'high_risk', 'multiple_accounts',
        'cards_expiring', 'cards_expired', 'password_update', 'account_recovery',
    ];

    public function audienceQuery(string $audience)
    {
        $businessRoles = self::BUSINESS_ROLES;

        if ($audience === 'business') {
            return User::query()->where(function ($q) use ($businessRoles) {
                $q->whereIn('role', $businessRoles)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', $businessRoles))
                    ->orWhere(function ($b) {
                        $b->whereNotNull('business_name')->where('business_name', '!=', '');
                    });
            });
        }

        return User::query()->where(function ($q) use ($businessRoles) {
            $q->where(function ($sub) {
                $sub->where('role', 'customer')
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'customer'));
            })
                ->whereNotIn('role', $businessRoles)
                ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', $businessRoles))
                ->where(function ($b) {
                    $b->whereNull('business_name')->orWhere('business_name', '');
                });
        });
    }

    /**
     * @param array{audience?:string,search?:string,status?:string,verification?:string,flag?:string,from?:string,to?:string,page?:int,per_page?:int} $params
     */
    public function directory(array $params): array
    {
        $audience = ($params['audience'] ?? 'customer') === 'business' ? 'business' : 'customer';
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 10)));
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = $this->audienceQuery($audience)->withCount('orders');

        $from = $this->parseDate($params['from'] ?? null);
        $to = $this->parseDate($params['to'] ?? null);
        if ($from) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }

        /** @var Collection<int, User> $users */
        $users = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();
        $rows = $this->buildRows($users, $audience);

        $summary = $this->summarize($rows);

        // Table filters (summary always reflects the full audience in the date range).
        $filtered = $rows->filter(function (array $row) use ($params) {
            $search = mb_strtolower(trim((string) ($params['search'] ?? '')));
            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['name'], $row['email'], $row['phone'], $row['account_number'],
                    $row['business_name'] ?? null, $row['address']['line1'] ?? null,
                    $row['address']['line2'] ?? null,
                ])));
                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }

            $status = $params['status'] ?? 'all';
            if ($status && $status !== 'all' && $row['status'] !== $status) {
                return false;
            }

            $verification = $params['verification'] ?? 'all';
            if ($verification === 'verified' && !$row['is_verified']) return false;
            if ($verification === 'unverified' && $row['is_verified']) return false;
            if ($verification === 'email_unverified' && $row['email_verified']) return false;
            if ($verification === 'phone_unverified' && $row['phone_verified']) return false;

            $flag = $params['flag'] ?? null;
            if ($flag && in_array($flag, self::FLAGS, true)) {
                if ($flag === 'flagged') {
                    return $row['risk']['flagged'];
                }
                if (in_array($flag, ['cards_expiring', 'cards_expired', 'password_update', 'account_recovery'], true)) {
                    return (bool) $row['alerts'][$flag];
                }
                return (bool) $row['risk'][$flag];
            }

            return true;
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'audience' => $audience,
            'rows' => $filtered->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total ? ($page - 1) * $perPage + 1 : 0,
                'to' => min($page * $perPage, $total),
            ],
            'summary' => $summary,
            'date_range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'earliest' => $users->min('created_at')?->toDateString(),
            ],
        ];
    }

    /** @param Collection<int, User> $users */
    private function buildRows(Collection $users, string $audience): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $ids = $users->pluck('id')->all();
        $emails = $users->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->all();

        $spent = $this->safe(fn () => DB::table('ecommerce_orders')
            ->whereIn('user_id', $ids)
            ->whereNotIn('status', ['cancelled', 'refunded', 'failed'])
            ->groupBy('user_id')
            ->select('user_id', DB::raw('SUM(total_amount) as total'))
            ->pluck('total', 'user_id'), collect());

        $addresses = $this->safe(fn () => DB::table('ecommerce_addresses')
            ->whereIn('user_id', $ids)
            ->orderByDesc('is_default')->orderByDesc('updated_at')
            ->get()->groupBy('user_id')->map->first(), collect());

        $walletAccounts = $this->safe(fn () => DB::table('ecommerce_wallet_accounts')
            ->whereIn('user_id', $ids)->get()->keyBy('user_id'), collect());

        $giftCards = $this->safe(fn () => DB::table('ecommerce_gift_cards')
            ->where('status', 'active')
            ->where(function ($q) use ($ids, $emails) {
                $q->whereIn('user_id', $ids)
                    ->orWhereIn(DB::raw('LOWER(recipient_email)'), $emails)
                    ->orWhereIn(DB::raw('LOWER(owner_email)'), $emails);
            })
            ->get(['user_id', 'recipient_email', 'owner_email', 'current_balance']), collect());

        $vouchers = $this->safe(fn () => DB::table('ecommerce_vouchers')
            ->where('status', 'active')
            ->where(function ($q) use ($ids, $emails) {
                $q->whereIn('user_id', $ids)->orWhereIn(DB::raw('LOWER(holder_email)'), $emails);
            })
            ->get(['user_id', 'holder_email', 'balance']), collect());

        $cards = $this->safe(fn () => DB::table('ecommerce_payment_methods')
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'exp_month', 'exp_year', 'card_fingerprint']), collect());

        $windowStart = now()->subDays(self::FAILED_LOGIN_WINDOW_DAYS);
        $logins = $this->safe(fn () => DB::table('user_login_histories')
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['user_id', 'ip_address', 'status', 'created_at']), collect())->groupBy('user_id');

        // Duplicate-account detection: shared phone or shared card fingerprint across ALL users.
        $phones = $users->pluck('phone')->filter()->map(fn ($p) => $this->normalizePhone($p))->filter()->unique()->values();
        $sharedPhones = $phones->isEmpty() ? collect() : $this->safe(fn () => User::query()
            ->whereNotNull('phone')->get(['id', 'phone'])
            ->groupBy(fn ($u) => $this->normalizePhone($u->phone))
            ->filter(fn ($group, $phone) => $phone !== '' && $group->count() > 1)
            ->keys(), collect());
        $fingerprints = $cards->pluck('card_fingerprint')->filter()->unique()->values();
        $sharedFingerprints = $fingerprints->isEmpty() ? collect() : $this->safe(fn () => DB::table('ecommerce_payment_methods')
            ->whereIn('card_fingerprint', $fingerprints->all())
            ->groupBy('card_fingerprint')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')
            ->pluck('card_fingerprint'), collect());

        $now = now();
        $warnUntil = $now->copy()->addDays(self::CARD_EXPIRY_WARNING_DAYS);

        return $users->map(function (User $user) use (
            $audience, $spent, $addresses, $walletAccounts, $giftCards, $vouchers, $cards, $logins,
            $sharedPhones, $sharedFingerprints, $windowStart, $now, $warnUntil
        ) {
            $email = mb_strtolower((string) $user->email);
            $status = $user->banned_at ? 'banned' : ($user->deactivated_at ? 'deactivated' : 'active');
            $emailVerified = (bool) $user->email_verified_at;
            $phoneVerified = (bool) $user->phone_verified_at;

            $address = $addresses->get($user->id);
            $walletAccount = $walletAccounts->get($user->id);

            $giftBalance = $giftCards->filter(fn ($g) => (int) $g->user_id === (int) $user->id
                || mb_strtolower((string) $g->recipient_email) === $email
                || mb_strtolower((string) $g->owner_email) === $email)->sum('current_balance');
            $voucherBalance = $vouchers->filter(fn ($v) => (int) $v->user_id === (int) $user->id
                || mb_strtolower((string) $v->holder_email) === $email)->sum('balance');

            $userCards = $cards->where('user_id', $user->id);
            $expiredCards = 0;
            $expiringCards = 0;
            foreach ($userCards as $card) {
                if (!$card->exp_month || !$card->exp_year) continue;
                $expiry = Carbon::create((int) $card->exp_year, (int) $card->exp_month, 1)->endOfMonth();
                if ($expiry->lt($now)) $expiredCards++;
                elseif ($expiry->lte($warnUntil)) $expiringCards++;
            }

            $userLogins = $logins->get($user->id, collect());
            $recent = $userLogins->filter(fn ($l) => Carbon::parse($l->created_at)->gte($windowStart));
            $failed = $recent->filter(fn ($l) => preg_match('/fail|denied|blocked|invalid/i', (string) $l->status))->count();
            $distinctIps = $recent->pluck('ip_address')->filter()->unique()->count();
            $suspicious = $recent->contains(fn ($l) => preg_match('/suspicious|unusual/i', (string) $l->status));

            $sharedPhone = $user->phone && $sharedPhones->contains($this->normalizePhone($user->phone));
            $sharedCard = $userCards->pluck('card_fingerprint')->filter()->intersect($sharedFingerprints)->isNotEmpty();

            $risk = [
                'failed_login' => $failed >= self::FAILED_LOGIN_THRESHOLD,
                'failed_login_count' => $failed,
                'unusual_login' => $suspicious || $distinctIps >= self::UNUSUAL_IP_THRESHOLD,
                'multiple_accounts' => $sharedPhone || $sharedCard,
                'high_risk' => $user->risk_level === 'high' || $failed >= self::FAILED_LOGIN_THRESHOLD * 2,
                'level' => $user->risk_level,
                'reason' => $user->risk_reason,
            ];
            $risk['flagged'] = $risk['failed_login'] || $risk['unusual_login'] || $risk['multiple_accounts'] || $risk['high_risk'];

            $lastLogin = $userLogins->filter(fn ($l) => !preg_match('/fail|denied|blocked|invalid/i', (string) $l->status))
                ->max('created_at');
            $lastActivity = collect([
                $user->last_login_at,
                $lastLogin ? Carbon::parse($lastLogin) : null,
                $walletAccount?->last_activity_at ? Carbon::parse($walletAccount->last_activity_at) : null,
                $user->updated_at,
            ])->filter()->max();

            $wallet = $walletAccount && (float) $walletAccount->store_credit_balance > 0
                ? (float) $user->wallet_balance + (float) $walletAccount->store_credit_balance
                : (float) $user->wallet_balance;

            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar ? $this->avatarUrl($user->avatar) : null,
                'email' => $user->email,
                'username' => $user->username,
                'gender' => $user->gender,
                'phone' => $user->phone,
                'account_number' => $this->accountNumber($user, $audience === 'business'),
                'address' => $this->formatAddress($address, $user),
                'orders_count' => (int) ($user->orders_count ?? 0),
                'total_spent' => round((float) ($spent->get($user->id) ?? 0), 2),
                'wallet_balance' => round($wallet, 2),
                'gift_card_balance' => round((float) $giftBalance, 2),
                'voucher_balance' => round((float) $voucherBalance, 2),
                'joined_at' => $user->created_at?->toIso8601String(),
                'last_activity_at' => $lastActivity?->toIso8601String(),
                'status' => $status,
                'banned_at' => $user->banned_at?->toIso8601String(),
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'email_verified' => $emailVerified,
                'phone_verified' => $phoneVerified,
                // "Verified" badge = email verified (phone is tracked separately in the overview).
                'is_verified' => $emailVerified,
                'alerts' => [
                    'cards_expiring' => $expiringCards,
                    'cards_expired' => $expiredCards,
                    'password_update' => (bool) $user->password_update_required_at,
                    'account_recovery' => (bool) $user->account_recovery_required_at,
                ],
                'risk' => $risk,
            ];

            if ($audience === 'business') {
                $row['business_name'] = $user->business_name ?: ($user->name ? $user->name . "'s Business" : 'Business Account');
                $row['contact_role'] = $user->job_title ?: 'Owner';
                $row['business_type'] = $user->business_type;
                $row['tax_id'] = $user->tax_id;
            }

            return $row;
        });
    }

    private function summarize(Collection $rows): array
    {
        $total = $rows->count();
        $emailVerified = $rows->where('email_verified', true)->count();
        $phoneVerified = $rows->where('phone_verified', true)->count();
        $flagged = $rows->filter(fn ($r) => $r['risk']['flagged']);

        return [
            'total' => $total,
            'active' => $rows->where('status', 'active')->count(),
            'deactivated' => $rows->where('status', 'deactivated')->count(),
            'banned' => $rows->where('status', 'banned')->count(),
            'high_risk' => $flagged->count(),
            'verification' => [
                'email' => [
                    'verified' => $emailVerified,
                    'unverified' => $total - $emailVerified,
                    'percent' => $total ? (int) round($emailVerified / $total * 100) : 0,
                ],
                'phone' => [
                    'verified' => $phoneVerified,
                    'unverified' => $total - $phoneVerified,
                    'percent' => $total ? (int) round($phoneVerified / $total * 100) : 0,
                ],
            ],
            'alerts' => [
                'cards_expiring' => (int) $rows->sum(fn ($r) => $r['alerts']['cards_expiring']),
                'cards_expired' => (int) $rows->sum(fn ($r) => $r['alerts']['cards_expired']),
                'password_update' => $rows->filter(fn ($r) => $r['alerts']['password_update'])->count(),
                'account_recovery' => $rows->filter(fn ($r) => $r['alerts']['account_recovery'])->count(),
            ],
            'risk' => [
                'flagged' => $flagged->count(),
                'flagged_accounts' => $flagged->take(5)->map(fn ($r) => [
                    'id' => $r['id'],
                    'account_number' => $r['account_number'],
                    'name' => $r['business_name'] ?? $r['name'],
                ])->values()->all(),
                'failed_login' => $rows->filter(fn ($r) => $r['risk']['failed_login'])->count(),
                'unusual_login' => $rows->filter(fn ($r) => $r['risk']['unusual_login'])->count(),
                'high_risk' => $rows->filter(fn ($r) => $r['risk']['high_risk'])->count(),
                'multiple_accounts' => $rows->filter(fn ($r) => $r['risk']['multiple_accounts'])->count(),
            ],
        ];
    }

    public function accountNumber(User $user, bool $isBusiness = false): string
    {
        if ($user->customer_account_number) {
            if ($isBusiness && str_starts_with($user->customer_account_number, 'ME') && !str_starts_with($user->customer_account_number, 'MEB')) {
                return 'MEB' . substr($user->customer_account_number, 2);
            }

            return $user->customer_account_number;
        }
        $year = $user->created_at ? $user->created_at->format('Y') : now()->format('Y');
        $prefix = $isBusiness ? 'MEB' : 'ME';

        return $prefix . $year . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    }

    private function formatAddress($address, User $user): array
    {
        if ($address) {
            $line1 = trim(implode(', ', array_filter([$address->address_line_1 ?: $address->address, $address->address_line_2])));
            $cityLine = trim(implode(', ', array_filter([
                $address->city,
                trim(implode(' ', array_filter([$address->state, $address->postal_code ?: $address->zip_code]))),
            ])));

            return [
                'line1' => $line1 ?: null,
                'line2' => $cityLine ?: null,
                'country' => $this->countryName($address->country),
            ];
        }

        if ($user->address) {
            $parts = array_map('trim', explode(',', $user->address));
            $country = count($parts) > 2 ? array_pop($parts) : null;

            return [
                'line1' => array_shift($parts) ?: null,
                'line2' => $parts ? implode(', ', $parts) : null,
                'country' => $country,
            ];
        }

        return ['line1' => null, 'line2' => null, 'country' => null];
    }

    private function countryName(?string $country): ?string
    {
        if (!$country) return null;
        $map = ['US' => 'United States', 'USA' => 'United States', 'GD' => 'Grenada', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'UK' => 'United Kingdom', 'PK' => 'Pakistan'];

        return $map[strtoupper($country)] ?? $country;
    }

    private function avatarUrl(string $avatar): string
    {
        if (preg_match('#^(https?:)?//|^data:#', $avatar)) return $avatar;

        return url('storage/' . ltrim(preg_replace('#^/?storage/#', '', $avatar), '/'));
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? $digits : '';
    }

    private function parseDate($value): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safe(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
