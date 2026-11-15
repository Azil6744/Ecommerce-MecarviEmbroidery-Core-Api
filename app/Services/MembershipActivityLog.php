<?php

namespace App\Services;

use App\Models\EcommerceMembershipActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin actions on a membership, for the member's Activity Log.
 *
 * Central memberships already get an audit entry from Central for every change, so this records:
 *  - every admin action on a local (Core-only) membership, and
 *  - actions Central does not see at all (for example a message to the member).
 */
class MembershipActivityLog
{
    public static function admin(
        Request $request,
        string $source,
        ?int $membershipId,
        ?int $userId,
        string $action,
        array $fields = []
    ): void {
        try {
            $admin = $request->user();
            EcommerceMembershipActivity::create(array_merge([
                'membership_id' => $source === 'local' ? $membershipId : null,
                'user_id' => $userId,
                'source' => $source,
                'action' => $action,
                'performed_by' => 'admin',
                'actor_name' => $admin?->name,
                'ip_address' => $request->ip(),
            ], $fields, [
                'details' => array_merge(
                    $fields['details'] ?? [],
                    $source === 'central' ? ['central_membership_id' => $membershipId] : []
                ),
            ]));
        } catch (\Throwable $e) {
            Log::warning('Membership activity log (admin) failed: ' . $e->getMessage());
        }
    }
}
