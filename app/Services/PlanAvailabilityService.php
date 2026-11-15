<?php

namespace App\Services;

use App\Http\Controllers\Api\Ecommerce\CustomerMembershipController;
use App\Models\EcommerceMembership;
use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reads the admin plan editor's "Plan Information" options (status, visibility,
 * availability status, availability dates, eligibility, featured) and decides
 * whether a plan is listed on the customer/business membership pages and whether
 * a given user may subscribe or switch to it.
 */
class PlanAvailabilityService
{
    public const ACTIVE_STATUSES = ['active', 'featured', 'published'];

    /** Normalised view of the plan's availability options. */
    public static function rules(EcommerceSubscriptionPlan $plan): array
    {
        $rules = is_array($plan->availability_rules) ? $plan->availability_rules : [];

        $visibilityRaw = strtolower((string) ($rules['visibility'] ?? ''));
        $visibility = match (true) {
            str_contains($visibilityRaw, 'archiv') => 'archived',
            str_contains($visibilityRaw, 'admin') => 'admin',
            str_contains($visibilityRaw, 'hidden') || str_contains($visibilityRaw, 'direct') => 'direct_link',
            default => 'listed',
        };

        $availabilityRaw = strtolower((string) ($rules['status'] ?? ''));
        $availability = match (true) {
            str_contains($availabilityRaw, 'inactive') || str_contains($availabilityRaw, 'expired') => 'inactive',
            str_contains($availabilityRaw, 'scheduled') || str_contains($availabilityRaw, 'coming') => 'coming_soon',
            default => 'available',
        };

        $eligibilityRaw = strtolower((string) ($rules['eligibility'] ?? ''));
        $eligibility = match (true) {
            str_contains($eligibilityRaw, 'business') => 'business',
            str_contains($eligibilityRaw, 'vip') || str_contains($eligibilityRaw, 'invite') => 'vip',
            str_contains($eligibilityRaw, 'first') => 'first_time',
            default => 'all',
        };

        $status = strtolower((string) ($plan->status ?? 'active'));
        $now = now();
        $notStarted = $plan->effective_date && $plan->effective_date->gt($now);
        $ended = $plan->retirement_date && $plan->retirement_date->lte($now);

        // Effective availability: an end date that has passed or an inactive status wins,
        // a future start date reads as "coming soon".
        $effective = $availability;
        if ($ended) {
            $effective = 'inactive';
        } elseif ($effective === 'available' && $notStarted) {
            $effective = 'coming_soon';
        }

        return [
            'status_active' => in_array($status, self::ACTIVE_STATUSES, true),
            'visibility' => $visibility,
            'availability' => $effective,
            'eligibility' => $eligibility,
            'featured' => ($rules['featured'] ?? false) === true || $status === 'featured',
            'starts_at' => $plan->effective_date?->toIso8601String(),
            'ends_at' => $plan->retirement_date?->toIso8601String(),
        ];
    }

    /** Whether the plan appears in the public plan list (membership pages). */
    public static function isListed(EcommerceSubscriptionPlan $plan): bool
    {
        $r = self::rules($plan);

        return $r['status_active'] && $r['visibility'] === 'listed' && $r['availability'] !== 'inactive';
    }

    /** Whether the plan can be reached at all by customers (listed or via direct link). */
    public static function isReachable(EcommerceSubscriptionPlan $plan): bool
    {
        $r = self::rules($plan);

        return $r['status_active'] && in_array($r['visibility'], ['listed', 'direct_link'], true);
    }

    /**
     * Returns null when $user may subscribe/switch to $plan, otherwise a customer-facing reason.
     * $isPlanChange: the user already has a membership and is upgrading/downgrading.
     */
    public static function denialReason(EcommerceSubscriptionPlan $plan, $user, ?Request $request = null, bool $isPlanChange = false): ?string
    {
        $r = self::rules($plan);

        if (! self::isReachable($plan)) {
            return 'This plan is not available.';
        }
        if ($r['availability'] === 'coming_soon') {
            return $plan->effective_date && $plan->effective_date->isFuture()
                ? "This plan opens on {$plan->effective_date->format('M j, Y')}."
                : 'This plan is coming soon and cannot be purchased yet.';
        }
        if ($r['availability'] === 'inactive') {
            return 'This plan is no longer available.';
        }

        if (! $user) {
            return $r['eligibility'] === 'all' ? null : 'Please sign in to check if you are eligible for this plan.';
        }

        $userType = strtolower((string) ($user->account_type ?? '')) === 'business'
            || in_array(strtolower((string) ($user->role ?? '')), ['business', 'business_owner', 'business_user', 'company'], true)
            || filled($user->business_name ?? null)
            ? 'business' : 'personal';

        switch ($r['eligibility']) {
            case 'business':
                if ($userType !== 'business') {
                    return 'This plan is only available to business customers.';
                }
                break;
            case 'first_time':
                if ($isPlanChange || self::hasHadPaidMembership($user, $request)) {
                    return 'This plan is only available to first-time members.';
                }
                break;
            case 'vip':
                if (! self::hasPaidMembership($user, $request)) {
                    return 'This plan is only available to invited / VIP members.';
                }
                break;
        }

        return null;
    }

    /** Fields added to each plan in the public list so the panels can render state. */
    public static function presentFor(EcommerceSubscriptionPlan $plan, $user, ?Request $request = null): array
    {
        $r = self::rules($plan);
        $reason = self::denialReason($plan, $user, $request);

        return [
            'is_featured' => $r['featured'],
            'availability_status' => $r['availability'],
            'eligibility' => $r['eligibility'],
            'available_from' => $r['starts_at'],
            'available_until' => $r['ends_at'],
            'can_subscribe' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private static function currentMembershipRow($user, ?Request $request): ?array
    {
        if (! $request) {
            return null;
        }
        try {
            $found = CustomerMembershipController::resolveCustomerMembership($request);

            return $found['row'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Plan eligibility: membership lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    private static function hasPaidMembership($user, ?Request $request): bool
    {
        $row = self::currentMembershipRow($user, $request);
        if ($row) {
            $status = strtolower(str_replace(' ', '_', (string) ($row['status'] ?? '')));
            if ((float) ($row['price'] ?? 0) > 0 && in_array($status, ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade', 'pending_cancellation'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function hasHadPaidMembership($user, ?Request $request): bool
    {
        if (self::hasPaidMembership($user, $request)) {
            return true;
        }
        $row = self::currentMembershipRow($user, $request);
        if ($row && (float) ($row['price'] ?? 0) > 0) {
            return true;
        }

        return EcommerceMembership::where('user_id', $user->id)->where('price', '>', 0)->exists();
    }
}
