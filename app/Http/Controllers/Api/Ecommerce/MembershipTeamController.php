<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\MembershipTeamSeat;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MembershipTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer-facing team seats:
 *  - owners of a live business membership manage their team;
 *  - invited people accept or decline from their own panel.
 */
class MembershipTeamController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $this->ownedMembership($request);

        $data = [
            'is_owner' => (bool) $membership,
            'membership' => null,
            'seats_total' => 1,
            'seats_used' => 1,
            'members' => [],
            'invitations' => $this->invitationsFor($user),
            'shared_with_me' => MembershipTeamSeat::where('member_user_id', $user->id)->where('status', 'active')
                ->with('owner:id,name,email')->get()
                ->map(fn ($s) => ['id' => $s->id, 'owner' => $s->owner?->name ?: $s->owner?->email, 'since' => optional($s->accepted_at)->toIso8601String()])
                ->values(),
        ];

        if ($membership) {
            $plan = MembershipTeamService::planForMembership($membership);
            $seats = MembershipTeamSeat::where('owner_user_id', $user->id)
                ->where('central_membership_id', $membership['id'])
                ->whereIn('status', MembershipTeamSeat::LIVE)
                ->orderBy('created_at')
                ->get();
            $data['membership'] = ['id' => $membership['id'], 'plan_name' => $membership['plan_name'] ?? $plan?->name, 'status' => $membership['status'] ?? null];
            $data['seats_total'] = MembershipTeamService::seatsForPlan($plan);
            $data['seats_used'] = 1 + $seats->count();
            $data['members'] = $seats->map(fn (MembershipTeamSeat $s) => $this->seatPayload($s))->values();
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255', 'name' => 'nullable|string|max:120']);
        $user = $request->user();
        $email = strtolower(trim($validated['email']));

        $membership = $this->ownedMembership($request);
        if (! $membership) {
            return response()->json(['success' => false, 'message' => 'You need an active business membership to add team members.'], 422);
        }
        if ($email === strtolower((string) $user->email)) {
            return response()->json(['success' => false, 'message' => "You're already on this membership as the owner."], 422);
        }

        $plan = MembershipTeamService::planForMembership($membership);
        $total = MembershipTeamService::seatsForPlan($plan);
        if ($total <= 1) {
            return response()->json(['success' => false, 'message' => 'Your plan does not include team seats. Upgrade to a plan with team seats to add members.'], 422);
        }

        $seat = DB::transaction(function () use ($user, $membership, $email, $validated, $total) {
            $live = MembershipTeamSeat::where('owner_user_id', $user->id)
                ->where('central_membership_id', $membership['id'])
                ->whereIn('status', MembershipTeamSeat::LIVE)
                ->lockForUpdate()
                ->get();

            if ($live->contains(fn ($s) => strtolower($s->member_email) === $email)) {
                return 'duplicate';
            }
            if (1 + $live->count() >= $total) {
                return 'full';
            }

            $invitee = User::whereRaw('LOWER(email) = ?', [$email])->first();

            return MembershipTeamSeat::create([
                'owner_user_id' => $user->id,
                'central_membership_id' => $membership['id'],
                'member_email' => $email,
                'member_user_id' => $invitee?->id,
                'member_name' => $validated['name'] ?? $invitee?->name,
                'status' => 'invited',
                'invited_at' => now(),
            ]);
        });

        if ($seat === 'duplicate') {
            return response()->json(['success' => false, 'message' => 'That person is already on your team or has a pending invitation.'], 422);
        }
        if ($seat === 'full') {
            return response()->json(['success' => false, 'message' => "All {$total} seats on your plan are in use. Remove someone or upgrade for more seats."], 422);
        }

        if ($seat->member_user_id) {
            UserNotification::create([
                'user_id' => $seat->member_user_id,
                'category' => 'memberships',
                'type' => 'membership.team_invite',
                'title' => 'Team membership invitation',
                'body' => ($user->name ?: $user->email) . ' invited you to share their ' . ($membership['plan_name'] ?? 'business') . ' membership.',
                'link' => '/memberships',
                'dedupe_key' => 'team-invite-' . $seat->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $seat->member_user_id
                ? 'Invitation sent. They can accept it from their account.'
                : 'Invitation saved. It will appear in their account as soon as they sign up with ' . $email . '.',
            'data' => $this->seatPayload($seat),
        ], 201);
    }

    public function remove(Request $request, int $seat): JsonResponse
    {
        $row = MembershipTeamSeat::where('owner_user_id', $request->user()->id)->whereIn('status', MembershipTeamSeat::LIVE)->findOrFail($seat);
        $row->update(['status' => 'removed', 'removed_at' => now()]);

        if ($row->member_user_id) {
            UserNotification::create([
                'user_id' => $row->member_user_id,
                'category' => 'memberships',
                'type' => 'membership.team_removed',
                'title' => 'Removed from team membership',
                'body' => 'You no longer share ' . ($request->user()->name ?: 'the owner') . "'s membership benefits.",
                'link' => '/memberships',
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Team member removed.']);
    }

    public function respond(Request $request, int $seat, string $decision): JsonResponse
    {
        if (! in_array($decision, ['accept', 'decline'], true)) {
            return response()->json(['success' => false, 'message' => 'Unknown response.'], 422);
        }
        $user = $request->user();
        $row = MembershipTeamSeat::where('status', 'invited')
            ->where(fn ($q) => $q->where('member_user_id', $user->id)->orWhereRaw('LOWER(member_email) = ?', [strtolower((string) $user->email)]))
            ->findOrFail($seat);

        if ($decision === 'accept') {
            $owner = collect(MembershipTeamService::centralMemberships())->first(fn ($m) => (int) ($m['id'] ?? 0) === (int) $row->central_membership_id);
            if (! MembershipTeamService::isLive($owner)) {
                $row->update(['status' => 'removed', 'removed_at' => now()]);

                return response()->json(['success' => false, 'message' => "This invitation is no longer valid: the owner's membership isn't active."], 422);
            }
            $row->update(['status' => 'active', 'member_user_id' => $user->id, 'member_name' => $row->member_name ?: $user->name, 'accepted_at' => now()]);
        } else {
            $row->update(['status' => 'declined', 'member_user_id' => $user->id]);
        }

        UserNotification::create([
            'user_id' => $row->owner_user_id,
            'category' => 'memberships',
            'type' => 'membership.team_' . $decision,
            'title' => $decision === 'accept' ? 'Team invitation accepted' : 'Team invitation declined',
            'body' => ($user->name ?: $user->email) . ($decision === 'accept' ? ' joined your team membership.' : ' declined your team invitation.'),
            'link' => '/memberships',
        ]);

        return response()->json([
            'success' => true,
            'message' => $decision === 'accept' ? "You've joined the team. Membership benefits now apply to your orders." : 'Invitation declined.',
        ]);
    }

    /** Admin: the team on a Central membership. */
    public function adminShow(int $id): JsonResponse
    {
        $membership = collect(MembershipTeamService::centralMemberships())->first(fn ($m) => (int) ($m['id'] ?? 0) === $id);
        $plan = $membership ? MembershipTeamService::planForMembership($membership) : null;
        $seats = MembershipTeamSeat::where('central_membership_id', $id)->orderBy('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'seats_total' => MembershipTeamService::seatsForPlan($plan),
                'seats_used' => 1 + $seats->whereIn('status', MembershipTeamSeat::LIVE)->count(),
                'members' => $seats->map(fn (MembershipTeamSeat $s) => $this->seatPayload($s))->values(),
            ],
        ]);
    }

    /** Admin: remove a seat. */
    public function adminRemove(int $id, int $seat): JsonResponse
    {
        $row = MembershipTeamSeat::where('central_membership_id', $id)->whereIn('status', MembershipTeamSeat::LIVE)->findOrFail($seat);
        $row->update(['status' => 'removed', 'removed_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Team member removed.']);
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    /** The caller's own live business membership (Central), if any. */
    private function ownedMembership(Request $request): ?array
    {
        $user = $request->user();
        $rows = MembershipTeamService::centralMemberships();
        foreach ($rows as $m) {
            if (strtolower((string) ($m['user']['email'] ?? '')) !== strtolower((string) $user->email)) {
                continue;
            }
            if (strtolower((string) ($m['account_type'] ?? '')) !== 'business' || ! MembershipTeamService::isLive($m)) {
                continue;
            }

            return $m;
        }

        return null;
    }

    private function invitationsFor(User $user)
    {
        return MembershipTeamSeat::where('status', 'invited')
            ->where(fn ($q) => $q->where('member_user_id', $user->id)->orWhereRaw('LOWER(member_email) = ?', [strtolower((string) $user->email)]))
            ->with('owner:id,name,email')
            ->latest()
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'owner' => $s->owner?->name ?: $s->owner?->email,
                'invited_at' => optional($s->invited_at)->toIso8601String(),
            ])
            ->values();
    }

    private function seatPayload(MembershipTeamSeat $s): array
    {
        return [
            'id' => $s->id,
            'email' => $s->member_email,
            'name' => $s->member_name,
            'status' => $s->status,
            'invited_at' => optional($s->invited_at)->toIso8601String(),
            'accepted_at' => optional($s->accepted_at)->toIso8601String(),
        ];
    }
}
