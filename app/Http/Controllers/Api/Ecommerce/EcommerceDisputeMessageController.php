<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceDispute;
use App\Models\EcommerceDisputeMessage;
use App\Models\EcommerceOrder;
use Illuminate\Http\Request;

/**
 * Two-way message thread on a dispute.
 *  - Customers see (and post) customer-visible messages on their own disputes.
 *  - Admins see everything, including internal notes, and can post either kind.
 */
class EcommerceDisputeMessageController extends Controller
{
    public function index(Request $request, $disputeId)
    {
        $dispute = $this->resolveDispute($disputeId);
        if (!$dispute) {
            return response()->json(['success' => false, 'message' => 'Dispute not found'], 404);
        }

        $staff = $this->isStaff($request);
        if (!$staff && !$this->canAccess($request, $dispute)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = $dispute->messages()->with('user:id,name');
        if (!$staff) {
            $query->where('is_internal', false);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request, $disputeId)
    {
        $dispute = $this->resolveDispute($disputeId);
        if (!$dispute) {
            return response()->json(['success' => false, 'message' => 'Dispute not found'], 404);
        }

        $staff = $this->isStaff($request);
        if (!$staff && !$this->canAccess($request, $dispute)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!$staff && $dispute->status === 'Closed') {
            return response()->json([
                'success' => false,
                'message' => 'This dispute is closed. Please open a new dispute if you still need help.',
            ], 422);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        // Only staff can write internal notes; customers can never create them.
        $isInternal = $staff && (bool) ($validated['is_internal'] ?? false);

        $user = $request->user();
        $message = EcommerceDisputeMessage::create([
            'ecommerce_dispute_id' => $dispute->id,
            'user_id' => $user?->id,
            'sender_type' => $staff ? EcommerceDisputeMessage::SENDER_ADMIN : EcommerceDisputeMessage::SENDER_CUSTOMER,
            'sender_name' => $staff
                ? ($user?->name ?: 'Mecarvi Support')
                : ($dispute->customer_name ?: ($user?->name ?: 'Customer')),
            'body' => trim($validated['body']),
            'is_internal' => $isInternal,
        ]);

        // A customer answering an "Awaiting Response" case moves it back into review.
        if (!$staff && $dispute->status === 'Awaiting Response') {
            $dispute->update(['status' => 'Under Review']);
        }

        return response()->json([
            'success' => true,
            'message' => $isInternal ? 'Internal note added.' : 'Message sent.',
            'data' => $message->load('user:id,name'),
        ], 201);
    }

    private function isStaff(Request $request): bool
    {
        $user = $request->user();

        return $request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()));
    }

    private function resolveDispute($id): ?EcommerceDispute
    {
        if (is_numeric($id) && request()->user()) {
            return EcommerceDispute::where('id', (int) $id)
                ->orWhere('dispute_number', (string) $id)
                ->first();
        }

        return EcommerceDispute::where('dispute_number', (string) $id)->first();
    }

    private function canAccess(Request $request, EcommerceDispute $dispute): bool
    {
        $user = $request->user();

        if ($user) {
            if ($dispute->user_id && (int) $dispute->user_id === (int) $user->id) {
                return true;
            }

            return $dispute->order_number
                && EcommerceOrder::where('user_id', $user->id)
                    ->where('order_number', $dispute->order_number)
                    ->exists();
        }

        // Guests must present the dispute number together with the email it was filed under.
        return $request->filled('email')
            && $dispute->email
            && strtolower((string) $request->input('email')) === strtolower((string) $dispute->email);
    }
}
