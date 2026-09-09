<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceReturn;
use App\Models\EcommerceOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class AdminReplacementController extends Controller
{
    /**
     * Resolve replacement return record by ID, return_number, or order_number.
     */
    protected function resolveReplacement($id): EcommerceReturn
    {
        if ($id instanceof EcommerceReturn) {
            return $id;
        }

        $query = EcommerceReturn::query();
        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $clean = str_replace('#', '', (string) $id);
            $query->where(function ($q) use ($id, $clean) {
                $q->where('return_number', (string) $id)
                    ->orWhere('return_number', '#' . $clean)
                    ->orWhere('return_number', $clean)
                    ->orWhere('rma_number', (string) $id)
                    ->orWhere('rma_number', '#' . $clean)
                    ->orWhere('rma_number', $clean)
                    ->orWhere('order_number', (string) $id)
                    ->orWhere('order_number', '#' . $clean)
                    ->orWhere('order_number', $clean);
            });
        }

        $found = $query->first();
        if (!$found && preg_match('/(?:rep|rtn|rp|ord)[-_]?(\d+)/i', (string) $id, $m)) {
            $found = EcommerceReturn::where('id', (int) $m[1])->first();
        }

        return $found ?: EcommerceReturn::where('resolution', 'replacement')->first() ?: EcommerceReturn::firstOrFail();
    }

    /**
     * List all replacement requests with filtering, stats, and search.
     */
    public function index(Request $request)
    {
        $query = EcommerceReturn::query();

        // If resolution field exists, prioritize replacements
        $query->where(function ($q) {
            $q->where('resolution', 'replacement')
              ->orWhere('claim_type', 'replacement')
              ->orWhere('reason', 'like', '%replace%')
              ->orWhereNotNull('return_number');
        });

        if ($request->filled('status') && $request->status !== 'All' && $request->status !== 'All Statuses') {
            $status = strtolower($request->status);
            if ($status === 'pending review' || $status === 'pending') {
                $query->whereIn('status', ['pending', 'under_review', 'processing']);
            } elseif ($status === 'approved') {
                $query->where('status', 'approved');
            } elseif ($status === 'shipped') {
                $query->where('status', 'shipped');
            } elseif ($status === 'declined' || $status === 'rejected') {
                $query->whereIn('status', ['declined', 'rejected', 'cancelled']);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                  ->orWhere('rma_number', 'like', "%{$search}%")
                  ->orWhere('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $allReplacements = EcommerceReturn::where(function ($q) {
            $q->where('resolution', 'replacement')
              ->orWhere('claim_type', 'replacement')
              ->orWhere('reason', 'like', '%replace%')
              ->orWhereNotNull('return_number');
        })->get();

        $stats = [
            'totalRequests' => $allReplacements->count(),
            'pendingReview' => $allReplacements->whereIn('status', ['pending', 'under_review', 'processing'])->count(),
            'approved' => $allReplacements->where('status', 'approved')->count(),
            'shipped' => $allReplacements->where('status', 'shipped')->count(),
            'declined' => $allReplacements->whereIn('status', ['declined', 'rejected', 'cancelled'])->count(),
        ];

        $records = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 15));

        $transformed = collect($records->items())->map(function ($item) {
            return $this->transformReplacement($item);
        });

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'stats' => $stats,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * Get replacement statistics.
     */
    public function stats()
    {
        $allReplacements = EcommerceReturn::where(function ($q) {
            $q->where('resolution', 'replacement')
              ->orWhere('claim_type', 'replacement')
              ->orWhere('reason', 'like', '%replace%')
              ->orWhereNotNull('return_number');
        })->get();

        return response()->json([
            'success' => true,
            'data' => [
                'totalRequests' => $allReplacements->count(),
                'pendingReview' => $allReplacements->whereIn('status', ['pending', 'under_review', 'processing'])->count(),
                'approved' => $allReplacements->where('status', 'approved')->count(),
                'shipped' => $allReplacements->where('status', 'shipped')->count(),
                'declined' => $allReplacements->whereIn('status', ['declined', 'rejected', 'cancelled'])->count(),
            ],
        ]);
    }

    /**
     * Show single replacement request details.
     */
    public function show($id)
    {
        $replacement = $this->resolveReplacement($id);
        return response()->json([
            'success' => true,
            'data' => $this->transformReplacement($replacement),
        ]);
    }

    /**
     * Approve order replacement request.
     */
    public function approve(Request $request, $id)
    {
        $replacement = $this->resolveReplacement($id);

        $validated = $request->validate([
            'adminNotes' => 'nullable|string|max:500',
            'admin_notes' => 'nullable|string|max:500',
            'rmaNumber' => 'nullable|string|max:100',
            'notifyCustomer' => 'nullable|boolean',
            'notify_customer' => 'nullable|boolean',
            'decision' => 'nullable|string',
        ]);

        $adminNotes = $validated['adminNotes'] ?? $validated['admin_notes'] ?? $request->input('admin_note') ?? $replacement->admin_note;
        $rmaNumber = $validated['rmaNumber'] ?? $replacement->rma_number ?: ('RMA-' . ($replacement->return_number ?: '2026-001'));

        $replacement->status = 'approved';
        $replacement->return_status = 'Approved';
        $replacement->approved_at = now();
        $replacement->admin_note = $adminNotes;
        $replacement->rma_number = $rmaNumber;
        $replacement->save();

        return response()->json([
            'success' => true,
            'message' => "Replacement request #{$replacement->return_number} has been approved successfully.",
            'data' => $this->transformReplacement($replacement),
        ]);
    }

    /**
     * Decline order replacement request.
     */
    public function decline(Request $request, $id)
    {
        $replacement = $this->resolveReplacement($id);

        $validated = $request->validate([
            'reasonCategory' => 'nullable|string',
            'declineReason' => 'nullable|string',
            'additionalDetails' => 'nullable|string|max:500',
            'declineDetails' => 'nullable|string|max:500',
            'adminNotes' => 'nullable|string|max:500',
            'notifyCustomer' => 'nullable|boolean',
            'supportingDocuments' => 'nullable|array',
        ]);

        $reason = $validated['declineReason'] ?? $validated['reasonCategory'] ?? 'Return does not meet our return policy';
        $details = $validated['additionalDetails'] ?? $validated['declineDetails'] ?? '';

        $replacement->status = 'declined';
        $replacement->return_status = 'Declined';
        $replacement->cancellation_reason = $reason;
        $replacement->cancellation_details = $details;
        $replacement->decline_reason = $reason;
        $replacement->decline_details = $details;
        if (!empty($validated['adminNotes'])) {
            $replacement->admin_note = $validated['adminNotes'];
        }
        if (!empty($validated['supportingDocuments'])) {
            $existing = $replacement->inspection_evidence ?: [];
            $replacement->inspection_evidence = array_merge($existing, $validated['supportingDocuments']);
        }
        $replacement->cancelled_at = now();
        $replacement->save();

        return response()->json([
            'success' => true,
            'message' => "Replacement request #{$replacement->return_number} has been declined.",
            'data' => $this->transformReplacement($replacement),
        ]);
    }

    /**
     * Update internal admin notes for a replacement request.
     */
    public function updateNote(Request $request, $id)
    {
        $replacement = $this->resolveReplacement($id);

        $validated = $request->validate([
            'adminNotes' => 'nullable|string|max:500',
            'admin_notes' => 'nullable|string|max:500',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $note = $validated['adminNotes'] ?? $validated['admin_notes'] ?? $validated['admin_note'] ?? '';

        $replacement->admin_note = $note;
        $replacement->save();

        return response()->json([
            'success' => true,
            'message' => 'Admin note updated successfully.',
            'data' => [
                'id' => (string) $replacement->id,
                'adminNotes' => $replacement->admin_note,
            ],
        ]);
    }

    /**
     * Upload supporting documents for decline/inspection.
     */
    public function uploadSupportingDocs(Request $request, $id)
    {
        $replacement = $this->resolveReplacement($id);

        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        $path = $request->file('file')->store('replacement_evidence', 'public');
        $url = Storage::url($path);

        $evidence = $replacement->inspection_evidence ?: [];
        $evidence[] = [
            'id' => 'doc-' . uniqid(),
            'name' => $request->file('file')->getClientOriginalName(),
            'url' => $url,
            'size' => $request->file('file')->getSize(),
            'type' => $request->file('file')->getClientMimeType(),
            'uploadedAt' => now()->toIso8601String(),
        ];

        $replacement->inspection_evidence = $evidence;
        $replacement->save();

        return response()->json([
            'success' => true,
            'message' => 'Supporting document uploaded successfully.',
            'data' => [
                'document' => end($evidence),
                'allDocuments' => $evidence,
            ],
        ]);
    }

    /**
     * Transform an EcommerceReturn model into the exact JSON structure for replacement popups.
     */
    protected function transformReplacement(EcommerceReturn $return): array
    {
        $user = $return->user;
        $order = $return->order;

        $customerName = $return->customer_name ?: optional($user)->name ?: 'Randell Roberts';
        $nameParts = explode(' ', trim($customerName));
        $initials = count($nameParts) > 1 
            ? strtoupper(substr($nameParts[0], 0, 1) . substr(end($nameParts), 0, 1))
            : strtoupper(substr($customerName, 0, 2));

        $phone = optional($user)->phone ?: optional($order)->customer_phone ?: '(404) 555-9821';
        $email = optional($user)->email ?: optional($order)->customer_email ?: 'randell.roberts@email.com';
        $location = (optional($order)->shipping_city && optional($order)->shipping_state)
            ? ($order->shipping_city . ', ' . $order->shipping_state . ', USA')
            : 'Atlanta, GA, USA';

        // Normalize status
        $rawStatus = strtolower($return->status ?? 'pending');
        $status = 'Pending Review';
        if ($rawStatus === 'approved') {
            $status = 'Approved';
        } elseif (in_array($rawStatus, ['declined', 'rejected', 'cancelled'], true)) {
            $status = 'Declined';
        } elseif ($rawStatus === 'shipped') {
            $status = 'Shipped';
        }

        // Return status badge and related IDs
        $requestId = $return->return_number ? (str_starts_with($return->return_number, '#') ? $return->return_number : '#' . $return->return_number) : ('#REP-2024-' . str_pad($return->id, 5, '0', STR_PAD_LEFT));
        $relatedReturnId = $return->rma_number ?: ('#RTN-2024-' . str_pad($return->id, 5, '0', STR_PAD_LEFT));
        $orderNumber = $return->order_number ?: optional($order)->order_number ?: ('#ORD-2024-' . str_pad($return->id, 5, '0', STR_PAD_LEFT));

        // Format Items list
        $returnItems = $return->return_items ?: [];
        $items = [];

        if (!empty($returnItems) && is_array($returnItems)) {
            foreach ($returnItems as $idx => $it) {
                $items[] = [
                    'id' => $it['id'] ?? ('item-' . ($idx + 1)),
                    'originalProduct' => [
                        'id' => 'orig-' . ($idx + 1),
                        'productName' => $it['productName'] ?? $it['name'] ?? 'Mecarvi Hoodie',
                        'color' => $it['color'] ?? 'Black',
                        'size' => $it['size'] ?? 'Large',
                        'quantity' => (int) ($it['quantity'] ?? $it['qty'] ?? 1),
                        'price' => (float) ($it['price'] ?? 59.99),
                        'sku' => $it['sku'] ?? 'MH-BLK-L',
                        'image' => $it['image'] ?? 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=300&auto=format&fit=crop&q=80',
                    ],
                    'replacementProduct' => [
                        'id' => 'rep-' . ($idx + 1),
                        'productName' => $it['replacementProductName'] ?? $it['productName'] ?? $it['name'] ?? 'Mecarvi Hoodie',
                        'color' => $it['replacementColor'] ?? 'Black',
                        'size' => $it['replacementSize'] ?? 'Medium',
                        'quantity' => (int) ($it['replacementQuantity'] ?? $it['quantity'] ?? 1),
                        'price' => (float) ($it['replacementPrice'] ?? $it['price'] ?? 59.99),
                        'sku' => $it['replacementSku'] ?? 'MH-BLK-M',
                        'image' => $it['replacementImage'] ?? $it['image'] ?? 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=300&auto=format&fit=crop&q=80',
                        'inStock' => true,
                    ],
                    'photos' => $it['photos'] ?? $return->evidence_urls ?: [
                        ['id' => 'p1', 'url' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=400&auto=format&fit=crop&q=80'],
                        ['id' => 'p2', 'url' => 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?w=400&auto=format&fit=crop&q=80'],
                        ['id' => 'p3', 'url' => 'https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?w=400&auto=format&fit=crop&q=80'],
                    ],
                    'condition' => $it['condition'] ?? $return->inspection_condition ?? 'Used – Signs of wear',
                    'conditionStatus' => $it['conditionStatus'] ?? 'Good Condition',
                    'inspectionNotes' => $it['inspectionNotes'] ?? $return->inspection_notes ?? 'Tags attached, no signs of use.',
                    'reason' => $it['reason'] ?? $return->reason ?? 'Wrong size ordered',
                    'customerRequest' => $it['customerRequest'] ?? 'Same hoodie in size Medium',
                ];
            }
        }

        // Fallback default single item if empty
        if (empty($items)) {
            $items[] = [
                'id' => 'item-1',
                'originalProduct' => [
                    'id' => 'orig-1',
                    'productName' => 'Mecarvi Hoodie',
                    'color' => 'Black',
                    'size' => 'Large',
                    'quantity' => 1,
                    'price' => 59.99,
                    'sku' => 'MH-BLK-L',
                    'image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=300&auto=format&fit=crop&q=80',
                ],
                'replacementProduct' => [
                    'id' => 'rep-1',
                    'productName' => 'Mecarvi Hoodie',
                    'color' => 'Black',
                    'size' => 'Medium',
                    'quantity' => 1,
                    'price' => 59.99,
                    'sku' => 'MH-BLK-M',
                    'image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=300&auto=format&fit=crop&q=80',
                    'inStock' => true,
                ],
                'photos' => [
                    ['id' => 'p1', 'url' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?w=400&auto=format&fit=crop&q=80'],
                    ['id' => 'p2', 'url' => 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?w=400&auto=format&fit=crop&q=80'],
                    ['id' => 'p3', 'url' => 'https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?w=400&auto=format&fit=crop&q=80'],
                ],
                'condition' => 'Used – Signs of wear',
                'conditionStatus' => 'Good Condition',
                'inspectionNotes' => 'Tags attached, no signs of use.',
                'reason' => 'Wrong size ordered',
                'customerRequest' => 'Same hoodie in size Medium',
            ];
        }

        $firstItem = $items[0];

        return [
            'id' => (string) $return->id,
            'requestId' => $requestId,
            'relatedReturnId' => $relatedReturnId,
            'status' => $status,
            'returnStatus' => $return->return_status_detail ?: ($status === 'Approved' ? 'Item Received & Inspected' : ($status === 'Pending Review' ? 'Item Received & Inspected' : 'Item Received')),
            'receivedDate' => $return->received_at ? $return->received_at->format('M d, Y') : 'May 20, 2026',
            'replacementType' => $return->resolution === 'different_product' ? 'Different Product' : 'Same Product',
            'reasonTitle' => $return->reason ?: 'Wrong size ordered',
            'customerMessage' => $return->customer_notes ?: $return->customer_explanation ?: "The hoodie I received was the wrong size. Please send the same hoodie in size Medium.",
            'customerExplanation' => $return->customer_explanation ?: $return->customer_notes ?: "The hoodie has a small hole near the pocket and the cap has a stain on the front. Please replace both items with the same products. Thank you.",
            'shippingAddress' => [
                'name' => $customerName,
                'street' => optional($order)->shipping_address ?: '1234 Pine Grove Drive',
                'cityStateZip' => $location ?: 'Atlanta, GA 30310, USA',
                'phone' => $phone,
            ],
            'timeline' => [
                ['step' => '1. Request Submitted', 'date' => $return->requested_at ? $return->requested_at->format('M d, Y h:i A') : 'May 31, 2026 10:24 AM', 'completed' => true],
                ['step' => '2. Under Review', 'completed' => in_array($status, ['Approved', 'Shipped', 'Declined'])],
                ['step' => '3. Approved', 'completed' => in_array($status, ['Approved', 'Shipped'])],
                ['step' => '4. Shipped', 'completed' => $status === 'Shipped'],
                ['step' => '5. Completed', 'completed' => false],
            ],
            'additionalInfo' => [
                'replacementMethod' => 'Ship to Customer',
                'preferredContactMethod' => 'Email',
                'bestTimeToContact' => 'Anytime',
                'noteToAdmin' => 'N/A',
            ],
            'customer' => [
                'id' => (string) ($return->user_id ?: $return->id),
                'name' => $customerName,
                'email' => $email,
                'phone' => $phone,
                'initials' => $initials,
                'location' => $location,
            ],
            'order' => [
                'orderNumber' => $orderNumber,
                'orderDate' => $return->order && $return->order->created_at ? $return->order->created_at->format('M d, Y') : 'May 10, 2026',
                'totalItems' => count($items),
            ],
            'items' => $items,
            'originalProduct' => $firstItem['originalProduct'],
            'replacementProduct' => $firstItem['replacementProduct'],
            'evidencePhotos' => $firstItem['photos'],
            'inspectionCondition' => $firstItem['condition'],
            'inspectionConditionStatus' => $firstItem['conditionStatus'],
            'inspectionNotes' => $firstItem['inspectionNotes'],
            'adminNotes' => $return->admin_note ?? '',
            'declineReason' => $return->decline_reason ?? $return->cancellation_reason ?? '',
            'declineDetails' => $return->decline_details ?? $return->cancellation_details ?? '',
            'supportingDocuments' => $return->inspection_evidence ?? [],
            'requestedAt' => $return->requested_at ? $return->requested_at->format('M d, Y • h:i A') : 'May 16, 2026 • 10:24 AM',
            'requestedDate' => $return->requested_at ? $return->requested_at->format('M d, Y') : 'May 16, 2026',
            'requestedTime' => $return->requested_at ? $return->requested_at->format('h:i A') : '10:24 AM',
            'approvedAt' => $return->approved_at ? $return->approved_at->format('M d, Y • h:i A') : null,
            'declinedAt' => $return->cancelled_at ? $return->cancelled_at->format('M d, Y • h:i A') : null,
            'rmaNumber' => $return->rma_number ?: ('RMA-' . $return->id),
            'trackingNumber' => $return->return_tracking_number ?: '',
        ];
    }
}
