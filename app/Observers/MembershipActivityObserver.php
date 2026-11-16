<?php

namespace App\Observers;

use App\Models\EcommerceMembershipActivity;
use App\Services\MembershipNotifier;

/**
 * Every recorded membership action (customer self-service and admin actions that
 * write local activity) produces an in-app notification for the member.
 */
class MembershipActivityObserver
{
    public function created(EcommerceMembershipActivity $activity): void
    {
        if (! $activity->user_id) {
            return;
        }

        // An admin's immediate cancellation is announced by the cancellation endpoint itself.
        if ($activity->action === 'cancelled' && $activity->performed_by === 'admin') {
            return;
        }

        $details = is_array($activity->details) ? $activity->details : [];

        MembershipNotifier::notify($activity->user_id, (string) $activity->action, [
            'from_plan' => $activity->from_plan,
            'to_plan' => $activity->to_plan,
            'amount' => $activity->amount,
            'date' => $details['effective_date'] ?? $details['resumes_at'] ?? $details['access_until'] ?? $details['scheduled_for'] ?? $details['effective'] ?? null,
            'performed_by' => $activity->performed_by,
        ], 'activity-' . $activity->id);
    }
}
