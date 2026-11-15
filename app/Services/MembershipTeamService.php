<?php

namespace App\Services;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\MembershipTeamSeat;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Team seats on business memberships.
 *
 * A business plan includes N seats (plan editor → "Team seats included"; the owner
 * uses one). The owner invites people by email; once they accept, they get the
 * owner's membership benefits at checkout for as long as that membership is live.
 */
class MembershipTeamService
{
    public const LIVE_STATUSES = ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade', 'pending_cancellation'];

    /** Total seats on the plan, including the owner. 1 = no team sharing. */
    public static function seatsForPlan(?EcommerceSubscriptionPlan $plan): int
    {
        if (! $plan) {
            return 1;
        }
        $cfg = is_array($plan->benefit_config) ? $plan->benefit_config : [];

        return max(1, (int) ($cfg['team_seats'] ?? 1));
    }

    public static function planForMembership(array $row): ?EcommerceSubscriptionPlan
    {
        if (! empty($row['plan_id']) && ($plan = EcommerceSubscriptionPlan::find($row['plan_id']))) {
            return $plan;
        }
        $name = (string) ($row['plan_name'] ?? '');

        return $name !== ''
            ? EcommerceSubscriptionPlan::whereRaw('LOWER(name) = ?', [strtolower($name)])->orWhere('internal_code', $name)->first()
            : null;
    }

    public static function isLive(?array $row): bool
    {
        return $row !== null && in_array(strtolower(str_replace(' ', '_', (string) ($row['status'] ?? ''))), self::LIVE_STATUSES, true);
    }

    /**
     * Memberships shared with this user through an accepted seat whose owner's
     * membership is still live. Shaped like Central membership rows so the
     * benefit engine can use them unchanged.
     */
    public static function sharedMembershipsFor(User $user): array
    {
        $seats = MembershipTeamSeat::where('member_user_id', $user->id)->where('status', 'active')->get();
        if ($seats->isEmpty()) {
            return [];
        }

        $rows = self::centralMemberships();
        $out = [];
        foreach ($seats as $seat) {
            $owner = collect($rows)->first(fn ($m) => (int) ($m['id'] ?? 0) === (int) $seat->central_membership_id);
            if (self::isLive($owner)) {
                $out[] = array_merge($owner, ['via_team_seat' => true, 'team_seat_id' => $seat->id]);
            }
        }

        return $out;
    }

    /** All Central memberships (internal admin endpoint). */
    public static function centralMemberships(): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')])
                ->timeout(8)
                ->get(rtrim((string) config('services.central_auth.url'), '/') . '/v1/internal/admin/memberships');

            return $response->successful() && is_array($response->json('data')) ? $response->json('data') : [];
        } catch (\Throwable $e) {
            Log::warning('Team seats: central membership lookup failed: ' . $e->getMessage());

            return [];
        }
    }
}
