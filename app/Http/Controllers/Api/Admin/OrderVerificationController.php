<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrderVerification;
use App\Models\EcommerceOrder;
use Illuminate\Http\Request;
use Carbon\Carbon;

class OrderVerificationController extends Controller
{
    /**
     * List all order verifications with search, status filters & summary stats
     */
    public function index(Request $request)
    {
        $query = EcommerceOrderVerification::with(['order', 'user']);

        if ($request->filled('status')) {
            $status = $request->get('status');
            if ($status === 'pending') {
                $query->whereIn('status', ['pending', 'pending_documents', 'action_required']);
            } elseif ($status === 'verified') {
                $query->whereIn('status', ['verified', 'cleared', 'completed']);
            } elseif ($status === 'needs_attention') {
                $query->whereIn('status', ['action_required', 'declined']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('flag_reason', 'like', "%{$search}%")
                  ->orWhere('reason_text', 'like', "%{$search}%")
                  ->orWhere('product_name', 'like', "%{$search}%")
                  ->orWhereHas('user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->get('risk_level'));
        }

        $perPage = (int) $request->get('per_page', 15);
        $verifications = $query->latest()->paginate($perPage);

        // Calculate summary counts
        $all = EcommerceOrderVerification::all();
        $totalCount = $all->count();
        $pendingCount = $all->whereIn('status', ['pending', 'pending_documents', 'action_required'])->count();
        $verifiedCount = $all->whereIn('status', ['verified', 'cleared', 'completed'])->count();
        $needsAttentionCount = $all->whereIn('status', ['action_required', 'declined'])->count();

        return response()->json([
            'success' => true,
            'data' => $verifications->items(),
            'current_page' => $verifications->currentPage(),
            'last_page' => $verifications->lastPage(),
            'total' => $verifications->total(),
            'per_page' => $verifications->perPage(),
            'stats' => [
                'total' => $totalCount,
                'pending' => $pendingCount,
                'verified' => $verifiedCount,
                'needs_attention' => $needsAttentionCount,
            ]
        ]);
    }

    /**
     * PostgreSQL safe finder for verification by ID or order_number
     */
    protected function findVerification($id, array $with = [])
    {
        $query = EcommerceOrderVerification::query();
        if (!empty($with)) {
            $query->with($with);
        }

        return $query->where(function($q) use ($id) {
            $q->where('order_number', (string) $id);
            if (is_numeric($id)) {
                $q->orWhere('id', (int) $id);
            }
        })->firstOrFail();
    }

    /**
     * Show single verification detail
     */
    public function show($id)
    {
        $verification = $this->findVerification($id, ['order', 'user']);

        return response()->json([
            'success' => true,
            'data' => $verification
        ]);
    }

    /**
     * Admin creates verification request for an order
     */
    public function store(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'risk_level' => 'nullable|string|in:high,medium,low',
            'reason_text' => 'nullable|string',
            'required_documents' => 'nullable|array',
            'deadline_days' => 'nullable|integer',
        ]);

        $orderNumber = $request->order_number;
        $days = (int) $request->get('deadline_days', 3);
        $deadline = Carbon::now()->addDays($days);

        $order = EcommerceOrder::with('items')->where('order_number', $orderNumber)
            ->orWhere('id', $orderNumber)
            ->first();

        $firstItem = $order ? $order->items->first() : null;
        $reqDocs = $request->get('required_documents');
        if (empty($reqDocs) || !is_array($reqDocs)) {
            $reqDocs = ['Payment Card (Front & Back)'];
        }

        // Prepare pending submitted_documents matching exactly the requested documents
        $initialSubmittedDocs = array_map(function($docName, $idx) {
            $type = strtolower(str_contains($docName, 'Card') ? 'card' : (str_contains($docName, 'ID') ? 'id' : 'document'));
            return [
                'id' => 'd' . ($idx + 1),
                'name' => $docName,
                'type' => $type,
                'status' => 'pending'
            ];
        }, $reqDocs, array_keys($reqDocs));

        $verification = EcommerceOrderVerification::updateOrCreate(
            ['order_number' => $order ? $order->order_number : $orderNumber],
            [
                'order_id' => $order ? $order->id : null,
                'user_id' => $order ? $order->user_id : $request->get('user_id', 24),
                'site_slug' => $request->get('site_slug', 'embroidery'),
                'risk_level' => $request->get('risk_level', 'medium'),
                'flag_reason' => $request->get('flag_reason', 'Admin requested identity verification.'),
                'reason_title' => 'Why do I need to verify my order?',
                'reason_text' => $request->get('reason_text', 'Please submit requested documents to continue order processing.'),
                'status' => 'action_required',
                'deadline_at' => $deadline,
                'total_amount' => $order ? $order->total_amount : $request->get('total_amount', 145.00),
                'payment_method' => $order ? $order->payment_method : $request->get('payment_method', 'Mastercard ending 7890'),
                'product_name' => $firstItem ? $firstItem->product_name : $request->get('product_name', 'Custom Order'),
                'product_specs' => $firstItem && !empty($firstItem->product_options) ? implode(' • ', array_values($firstItem->product_options)) : 'Standard Customization',
                'item_count' => $order ? $order->items->sum('quantity') : 1,
                'product_image' => $request->get('product_image', '/assets/images/order-verification/stickers.jpg'),
                'required_documents' => array_values($reqDocs),
                'submitted_documents' => $initialSubmittedDocs,
                'timeline' => [
                    ['title' => 'Verification Request Sent', 'date' => Carbon::now()->format('M d, Y • h:i A'), 'completed' => true],
                    ['title' => 'Response Received', 'date' => null, 'completed' => false],
                    ['title' => 'Review In Progress', 'date' => null, 'completed' => false],
                ],
                'internal_notes' => [
                    'Request updated by Administrator on ' . Carbon::now()->format('M d, Y h:i A')
                ],
            ]
        );

        // Notify customer that verification is required & record order status event
        try {
            app(\App\Services\EmailNotificationService::class)->sendVerificationEvent('order_verification_required', $verification);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of verification requirement: ' . $e->getMessage());
        }

        if ($order) {
            $order->recordStatusEvent(
                'pending_verification',
                'Order flagged for verification: ' . ($verification->flag_reason ?: 'Identity verification required'),
                $verification->reason_text
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification request saved and sent successfully.',
            'data' => $verification
        ], 200);
    }

    /**
     * Update verification status
     */
    public function updateStatus(Request $request, $id)
    {
        $verification = $this->findVerification($id);

        $request->validate([
            'status' => 'required|string',
        ]);

        $verification->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $verification
        ]);
    }

    /**
     * Approve verification
     */
    public function approve(Request $request, $id)
    {
        $verification = $this->findVerification($id);

        $timeline = $verification->timeline ?? [];
        $timeline[] = [
            'title' => 'Verification Approved',
            'date' => Carbon::now()->format('M d, Y • h:i A'),
            'completed' => true
        ];

        // Mark all submitted documents as verified
        $submittedDocs = $verification->submitted_documents ?? [];
        $verifiedDocs = array_map(function($doc) {
            $doc['status'] = 'verified';
            return $doc;
        }, $submittedDocs);

        $notes = $verification->internal_notes ?? [];
        $notes[] = 'Verification Approved by Admin on ' . Carbon::now()->format('M d, Y h:i A');

        $verification->update([
            'status' => 'verified',
            'verified_at' => Carbon::now(),
            'submitted_documents' => $verifiedDocs,
            'timeline' => $timeline,
            'internal_notes' => $notes,
        ]);

        // Notify customer that verification was approved
        try {
            app(\App\Services\EmailNotificationService::class)->sendVerificationEvent('order_verification_approved', $verification);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of verification approval: ' . $e->getMessage());
        }

        if ($verification->order) {
            $verification->order->recordStatusEvent(
                'verification_approved',
                'Order verification approved and cleared by administrator'
            );

            // If order was pending verification, return status to processing
            if (in_array(strtolower((string) $verification->order->status), ['pending_verification', 'verification', 'action_required'], true)) {
                $verification->order->update(['status' => 'processing']);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Order verification approved successfully.',
            'data' => $verification
        ]);
    }

    /**
     * Decline verification (Final Decision)
     */
    public function decline(Request $request, $id)
    {
        $verification = $this->findVerification($id);

        $declineReason = $request->input('decline_reason', 'Unable to verify payment method ownership and/or supporting documents.');

        $timeline = $verification->timeline ?? [];
        $timeline[] = [
            'title' => 'Decision Made (Declined)',
            'date' => Carbon::now()->format('M d, Y • h:i A'),
            'completed' => true
        ];

        $notes = $verification->internal_notes ?? [];
        $notes[] = 'Verification Declined (Final) on ' . Carbon::now()->format('M d, Y h:i A') . '. Reason: ' . $declineReason;

        $verification->update([
            'status' => 'declined',
            'declined_at' => Carbon::now(),
            'decline_reason' => $declineReason,
            'timeline' => $timeline,
            'internal_notes' => $notes,
        ]);

        // Notify customer that verification was declined
        try {
            app(\App\Services\EmailNotificationService::class)->sendVerificationEvent('order_verification_declined', $verification, [
                'decline_reason' => $declineReason,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of verification decline: ' . $e->getMessage());
        }

        if ($verification->order) {
            $verification->order->recordStatusEvent(
                'declined',
                'Order verification declined: ' . $declineReason,
                $declineReason
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Order verification declined (final decision recorded).',
            'data' => $verification
        ]);
    }

    /**
     * Request additional documents
     */
    public function requestAdditional(Request $request, $id)
    {
        $verification = $this->findVerification($id);

        $request->validate([
            'requested_documents' => 'nullable|array',
            'reason_text' => 'nullable|string',
        ]);

        $timeline = $verification->timeline ?? [];
        $timeline[] = [
            'title' => 'Additional Documents Requested',
            'date' => Carbon::now()->format('M d, Y • h:i A'),
            'completed' => true
        ];

        $notes = $verification->internal_notes ?? [];
        $notes[] = 'Additional documents requested on ' . Carbon::now()->format('M d, Y h:i A');

        $reqDocs = $request->input('requested_documents', $verification->required_documents);
        $reasonText = $request->input('reason_text', 'After reviewing your response, we need additional document(s) or details to complete the verification.');

        $verification->update([
            'status' => 'pending_documents',
            'reason_title' => 'We Need More Information',
            'reason_text' => $reasonText,
            'required_documents' => $reqDocs,
            'timeline' => $timeline,
            'internal_notes' => $notes,
        ]);

        // Notify customer that additional documents are required
        try {
            $reqDocsFormatted = is_array($reqDocs) ? implode(', ', $reqDocs) : (string) $reqDocs;
            app(\App\Services\EmailNotificationService::class)->sendVerificationEvent('order_verification_more_info', $verification, [
                'notes' => $reasonText,
                'required_documents' => $reqDocsFormatted,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of additional verification documents: ' . $e->getMessage());
        }

        if ($verification->order) {
            $verification->order->recordStatusEvent(
                'verification_more_info',
                'Additional verification documents requested from customer',
                $reasonText
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Additional documents requested from customer.',
            'data' => $verification
        ]);
    }

    /**
     * Add admin internal note
     */
    public function addNote(Request $request, $id)
    {
        $verification = $this->findVerification($id);

        $request->validate([
            'note' => 'required|string'
        ]);

        $notes = $verification->internal_notes ?? [];
        $notes[] = 'Admin Note (' . Carbon::now()->format('M d, Y h:i A') . '): ' . $request->input('note');

        $verification->update([
            'internal_notes' => $notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Internal note added.',
            'data' => $verification
        ]);
    }

    /**
     * Delete verification record
     */
    public function destroy($id)
    {
        $verification = $this->findVerification($id);

        $verification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Verification deleted successfully.'
        ]);
    }
}
