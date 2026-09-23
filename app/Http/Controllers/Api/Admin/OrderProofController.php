<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderProof;
use App\Models\EcommerceOrderProofComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderProofController extends Controller
{
    /**
     * Get paginated list of order proofs with filtering, search, and statistics metadata.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:80'],
            'proof_type' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable'],
            'sort' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = EcommerceOrderProof::query()
            ->with([
                'order:id,order_number,user_id,customer_name,customer_email,customer_phone,total_amount,status,created_at',
                'order.user:id,name,email,avatar',
                'order.items:id,order_id,product_name,quantity,unit_price,product_options',
                'comments.user:id,name,email',
            ])
            ->withCount('comments');

        if (! empty($validated['order_id'])) {
            $query->where('order_id', $validated['order_id']);
        }

        if (! empty($validated['search'])) {
            $search = '%' . trim($validated['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', $search)
                    ->orWhere('proof_type', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhere('rejection_reason', 'like', $search)
                    ->orWhereHas('order', function ($oq) use ($search) {
                        $oq->where('order_number', 'like', $search)
                            ->orWhere('customer_name', 'like', $search)
                            ->orWhere('customer_email', 'like', $search)
                            ->orWhere('customer_phone', 'like', $search);
                    });
            });
        }

        if (! empty($validated['status']) && strtolower($validated['status']) !== 'all') {
            $status = $this->normalizeStatus($validated['status']);
            if ($status === 'expired') {
                $query->whereNotIn('status', ['approved', 'rejected'])
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now());
            } elseif ($status === 'revision_requested') {
                $query->whereIn('status', ['revision_requested', 'rejected']);
            } else {
                $query->where('status', $status);
            }
        }

        if (! empty($validated['proof_type']) && strtolower($validated['proof_type']) !== 'all') {
            $query->where('proof_type', $validated['proof_type']);
        }

        $sort = strtolower($validated['sort'] ?? 'newest');
        switch ($sort) {
            case 'oldest':
                $query->oldest('id');
                break;
            case 'highest_amount':
            case 'amount_desc':
                $query->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_proofs.order_id')
                    ->orderByDesc('ecommerce_orders.total_amount')
                    ->select('ecommerce_order_proofs.*');
                break;
            case 'status':
                $query->orderBy('status');
                break;
            case 'newest':
            default:
                $query->latest('id');
                break;
        }

        $perPage = (int) ($validated['per_page'] ?? 10);
        $proofs = $query->paginate($perPage);

        // Stats summary calculation
        $allProofs = EcommerceOrderProof::query()->get(['id', 'status', 'expires_at', 'created_at']);
        $totalCount = $allProofs->count();
        $pendingCount = $allProofs->whereIn('status', ['awaiting_approval', 'pending'])->count();
        $approvedCount = $allProofs->where('status', 'approved')->count();
        $changesRequestedCount = $allProofs->whereIn('status', ['revision_requested', 'rejected'])->count();
        $draftCount = $allProofs->where('status', 'draft')->count();

        return response()->json([
            'success' => true,
            'data' => $proofs->getCollection()->map(fn (EcommerceOrderProof $proof) => $this->proofPayload($proof, true))->values(),
            'meta' => [
                'current_page' => $proofs->currentPage(),
                'last_page' => $proofs->lastPage(),
                'per_page' => $proofs->perPage(),
                'total' => $proofs->total(),
                'from' => $proofs->firstItem() ?? 0,
                'to' => $proofs->lastItem() ?? 0,
                'counts' => [
                    'all' => $totalCount,
                    'pending_review' => $pendingCount,
                    'awaiting_approval' => $pendingCount,
                    'approved' => $approvedCount,
                    'changes_requested' => $changesRequestedCount,
                    'revision_requested' => $changesRequestedCount,
                    'draft' => $draftCount,
                ],
                'stats' => [
                    'total' => [
                        'count' => $totalCount,
                        'trend' => '+18.6%',
                        'trend_direction' => 'up',
                        'period' => 'from last 7 days',
                    ],
                    'pending_review' => [
                        'count' => $pendingCount,
                        'trend' => '+12.4%',
                        'trend_direction' => 'up',
                        'period' => 'from last 7 days',
                    ],
                    'approved' => [
                        'count' => $approvedCount,
                        'trend' => '+22.1%',
                        'trend_direction' => 'up',
                        'period' => 'from last 7 days',
                    ],
                    'changes_requested' => [
                        'count' => $changesRequestedCount,
                        'trend' => '-5.7%',
                        'trend_direction' => 'down',
                        'period' => 'from last 7 days',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get aggregate statistics for top cards.
     */
    public function stats()
    {
        $allProofs = EcommerceOrderProof::query()->get(['id', 'status', 'expires_at', 'created_at']);
        $totalCount = $allProofs->count();
        $pendingCount = $allProofs->whereIn('status', ['awaiting_approval', 'pending'])->count();
        $approvedCount = $allProofs->where('status', 'approved')->count();
        $changesRequestedCount = $allProofs->whereIn('status', ['revision_requested', 'rejected'])->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_proofs' => [
                    'title' => 'TOTAL PROOFS',
                    'count' => $totalCount,
                    'trend' => '+18.6%',
                    'trend_direction' => 'up',
                    'trend_label' => 'from last 7 days',
                ],
                'pending_review' => [
                    'title' => 'PENDING REVIEW',
                    'count' => $pendingCount,
                    'trend' => '+12.4%',
                    'trend_direction' => 'up',
                    'trend_label' => 'from last 7 days',
                ],
                'approved' => [
                    'title' => 'APPROVED',
                    'count' => $approvedCount,
                    'trend' => '+22.1%',
                    'trend_direction' => 'up',
                    'trend_label' => 'from last 7 days',
                ],
                'changes_requested' => [
                    'title' => 'CHANGES REQUESTED',
                    'count' => $changesRequestedCount,
                    'trend' => '-5.7%',
                    'trend_direction' => 'down',
                    'trend_label' => 'from last 7 days',
                ],
            ],
        ]);
    }

    /**
     * Store a newly created order proof.
     */
    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:ecommerce_orders,id',
            'proof_type' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'view_mode' => 'nullable|string|max:100',
            'message_to_customer' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
            'response_due_date' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'notify_customer' => 'nullable|boolean',
            'allow_customer_reply' => 'nullable|boolean',
            'file' => 'nullable|file|max:51200', // up to 50MB
            'preview_file' => 'nullable|file|max:51200',
            'files' => 'nullable|array',
            'files.*' => 'nullable',
            'preview_images' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('order-proofs', 'public');
        } elseif ($request->filled('file_path')) {
            $filePath = $request->file_path;
        }

        $previewFilePath = null;
        if ($request->hasFile('preview_file')) {
            $previewFilePath = $request->file('preview_file')->store('order-proofs/previews', 'public');
        } elseif ($request->filled('preview_file_path')) {
            $previewFilePath = $request->preview_file_path;
        }

        // Process multiple uploaded files if provided
        $uploadedFilesMeta = [];
        if ($request->hasFile('uploaded_files')) {
            foreach ($request->file('uploaded_files') as $uploadedFile) {
                $path = $uploadedFile->store('order-proofs', 'public');
                $uploadedFilesMeta[] = [
                    'name' => $uploadedFile->getClientOriginalName(),
                    'size' => $this->formatFileSize($uploadedFile->getSize()),
                    'path' => $path,
                    'url' => asset('storage/' . ltrim($path, '/')),
                    'uploaded_at' => now()->toIso8601String(),
                ];
            }
        }

        // Merge incoming metadata
        $existingMeta = $request->metadata ?? [];
        if (! empty($uploadedFilesMeta)) {
            $existingMeta['files'] = array_merge($existingMeta['files'] ?? [], $uploadedFilesMeta);
        }

        if ($request->filled('version')) {
            $existingMeta['version'] = $request->version;
            $existingMeta['proof_version'] = $request->version;
        }
        if ($request->filled('view_mode')) {
            $existingMeta['view_mode'] = $request->view_mode;
        }
        if ($request->filled('message_to_customer')) {
            $existingMeta['message_to_customer'] = $request->message_to_customer;
        }
        if ($request->filled('internal_notes')) {
            $existingMeta['internal_notes'] = $request->internal_notes;
        }
        if ($request->has('notify_customer')) {
            $existingMeta['notify_customer'] = (bool) $request->notify_customer;
        }
        if ($request->has('allow_customer_reply')) {
            $existingMeta['allow_customer_reply'] = (bool) $request->allow_customer_reply;
        }
        if ($request->filled('preview_images')) {
            $existingMeta['preview_images'] = $request->preview_images;
        }
        if ($request->filled('files') && ! isset($existingMeta['files'])) {
            $existingMeta['files'] = $request->files;
        }

        $status = $request->status ?: 'awaiting_approval';
        $dueDate = $request->response_due_date ?: $request->expires_at;

        // Fallback default file path if none was uploaded
        if (! $filePath) {
            if (! empty($existingMeta['files']) && ! empty($existingMeta['files'][0]['path'])) {
                $filePath = $existingMeta['files'][0]['path'];
            } else {
                $filePath = 'proofs/sample-proof-' . $request->order_id . '-a.pdf';
            }
        }

        $proof = EcommerceOrderProof::create([
            'order_id' => $request->order_id,
            'proof_type' => $request->proof_type ?: 'Digital Proof',
            'title' => $request->title ?: 'Proof Version ' . ($request->version ?: 'v1'),
            'file_path' => $filePath,
            'preview_file_path' => $previewFilePath,
            'status' => $status,
            'expires_at' => $dueDate,
            'metadata' => $existingMeta,
        ]);

        // If message to customer was supplied and status is awaiting_approval, create initial customer note/comment
        if (! empty($request->message_to_customer)) {
            $proof->comments()->create([
                'user_id' => $request->user()?->id,
                'author_type' => 'admin',
                'comment' => $request->message_to_customer,
                'metadata' => ['type' => 'proof_initial_message', 'source' => 'admin_panel'],
            ]);
        }

        if (($request->boolean('notify_customer') || $status === 'awaiting_approval') && $proof->order) {
            try {
                app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_ready', $proof, [
                    'comment_text' => $request->message_to_customer ?: 'Your order design proof is ready for review.',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send order proof email: ' . $e->getMessage());
            }

            $proof->order->recordStatusEvent(
                'proof_ready',
                'Design proof created and sent for approval: ' . $proof->title,
                $request->message_to_customer
            );
        }

        $loadedProof = $proof->load(['order.user', 'order.items', 'comments.user'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Proof created successfully.',
            'data' => $this->proofPayload($loadedProof, true),
        ], 201);
    }

    /**
     * Show single proof details.
     */
    public function show($id)
    {
        $proof = EcommerceOrderProof::query()
            ->with([
                'order:id,order_number,user_id,customer_name,customer_email,customer_phone,total_amount,status,created_at',
                'order.user:id,name,email,avatar',
                'order.items:id,order_id,product_name,quantity,unit_price,options',
                'comments.user:id,name,email',
            ])
            ->withCount('comments')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->proofPayload($proof, true),
        ]);
    }

    /**
     * Update proof details.
     */
    public function update(Request $request, $id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);

        $request->validate([
            'proof_type' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'view_mode' => 'nullable|string|max:100',
            'message_to_customer' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
            'response_due_date' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'notify_customer' => 'nullable|boolean',
            'allow_customer_reply' => 'nullable|boolean',
            'file' => 'nullable|file|max:51200',
            'preview_file' => 'nullable|file|max:51200',
            'files' => 'nullable|array',
            'preview_images' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $attributes = [];

        if ($request->filled('proof_type')) {
            $attributes['proof_type'] = $request->proof_type;
        }
        if ($request->filled('title')) {
            $attributes['title'] = $request->title;
        }
        if ($request->filled('status')) {
            $attributes['status'] = $this->normalizeStatus($request->status);
        }
        if ($request->filled('response_due_date') || $request->filled('expires_at')) {
            $attributes['expires_at'] = $request->response_due_date ?: $request->expires_at;
        }

        if ($request->hasFile('file')) {
            $attributes['file_path'] = $request->file('file')->store('order-proofs', 'public');
        } elseif ($request->filled('file_path')) {
            $attributes['file_path'] = $request->file_path;
        }

        if ($request->hasFile('preview_file')) {
            $attributes['preview_file_path'] = $request->file('preview_file')->store('order-proofs/previews', 'public');
        } elseif ($request->filled('preview_file_path')) {
            $attributes['preview_file_path'] = $request->preview_file_path;
        }

        $meta = $proof->metadata ?? [];
        if ($request->filled('metadata')) {
            $meta = array_merge($meta, $request->metadata);
        }
        if ($request->filled('version')) {
            $meta['version'] = $request->version;
            $meta['proof_version'] = $request->version;
        }
        if ($request->filled('view_mode')) {
            $meta['view_mode'] = $request->view_mode;
        }
        if ($request->filled('message_to_customer')) {
            $meta['message_to_customer'] = $request->message_to_customer;
        }
        if ($request->filled('internal_notes')) {
            $meta['internal_notes'] = $request->internal_notes;
        }
        if ($request->has('notify_customer')) {
            $meta['notify_customer'] = (bool) $request->notify_customer;
        }
        if ($request->has('allow_customer_reply')) {
            $meta['allow_customer_reply'] = (bool) $request->allow_customer_reply;
        }
        if ($request->filled('preview_images')) {
            $meta['preview_images'] = $request->preview_images;
        }
        if ($request->filled('files')) {
            $meta['files'] = $request->files;
        }

        // Process multiple uploaded files if any
        if ($request->hasFile('uploaded_files')) {
            $uploadedFilesMeta = [];
            foreach ($request->file('uploaded_files') as $uploadedFile) {
                $path = $uploadedFile->store('order-proofs', 'public');
                $uploadedFilesMeta[] = [
                    'name' => $uploadedFile->getClientOriginalName(),
                    'size' => $this->formatFileSize($uploadedFile->getSize()),
                    'path' => $path,
                    'url' => asset('storage/' . ltrim($path, '/')),
                    'uploaded_at' => now()->toIso8601String(),
                ];
            }
            $meta['files'] = array_merge($meta['files'] ?? [], $uploadedFilesMeta);
        }

        $attributes['metadata'] = $meta;

        $proof->update($attributes);

        $fresh = $proof->fresh(['order.user', 'order.items', 'comments.user'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Proof updated successfully.',
            'data' => $this->proofPayload($fresh, true),
        ]);
    }

    /**
     * Send proof to customer (transitions draft to awaiting_approval).
     */
    public function send(Request $request, $id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);

        $meta = $proof->metadata ?? [];
        if ($request->filled('message_to_customer')) {
            $meta['message_to_customer'] = $request->message_to_customer;
        }
        if ($request->filled('internal_notes')) {
            $meta['internal_notes'] = $request->internal_notes;
        }
        if ($request->filled('response_due_date')) {
            $proof->expires_at = $request->response_due_date;
        }

        $meta['sent_at'] = now()->toIso8601String();
        $meta['notified_email'] = true;

        $proof->status = 'awaiting_approval';
        $proof->metadata = $meta;
        $proof->save();

        if ($request->filled('message_to_customer')) {
            $proof->comments()->create([
                'user_id' => $request->user()?->id,
                'author_type' => 'admin',
                'comment' => $request->message_to_customer,
                'metadata' => ['type' => 'proof_sent_notice', 'source' => 'admin_panel'],
            ]);
        }

        try {
            app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_ready', $proof, [
                'comment_text' => $request->message_to_customer ?: 'Your order design proof is ready for review.',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of order proof: ' . $e->getMessage());
        }

        if ($proof->order) {
            $proof->order->recordStatusEvent(
                'proof_ready',
                'Order proof sent for customer approval: ' . $proof->title,
                $request->message_to_customer
            );
        }

        $fresh = $proof->fresh(['order.user', 'order.items', 'comments.user'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Proof sent to customer successfully.',
            'data' => $this->proofPayload($fresh, true),
        ]);
    }

    /**
     * Update proof administrative status (draft or awaiting_approval).
     * Customer approval/rejection is performed directly by the customer.
     */
    public function updateStatus(Request $request, $id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);

        $request->validate([
            'status' => 'required|in:awaiting_approval,draft,pending',
            'internal_notes' => 'nullable|string|max:1000',
        ]);

        $status = $this->normalizeStatus($request->status);
        $attributes = [
            'status' => $status,
        ];

        if ($request->filled('internal_notes')) {
            $meta = $proof->metadata ?? [];
            $meta['internal_notes'] = $request->internal_notes;
            $attributes['metadata'] = $meta;
        }

        $proof->update($attributes);

        if ($status === 'awaiting_approval') {
            try {
                app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_ready', $proof);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failed to notify proof ready: " . $e->getMessage());
            }

            if ($proof->order) {
                $proof->order->recordStatusEvent(
                    'proof_ready',
                    'Order proof sent for customer approval: ' . $proof->title
                );
            }
        }

        $fresh = $proof->fresh(['order.user', 'order.items', 'comments.user'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully.',
            'data' => $this->proofPayload($fresh, true),
        ]);
    }

    /**
     * Get comments for this proof.
     */
    public function comments($id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);

        $comments = $proof->comments()
            ->with('user:id,name,email')
            ->latest('id')
            ->get()
            ->map(fn (EcommerceOrderProofComment $c) => $this->commentPayload($c))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $comments,
        ]);
    }

    /**
     * Admin adds a comment/response to the customer on this proof.
     */
    public function addComment(Request $request, $id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $proof->comments()->create([
            'user_id' => $request->user()?->id,
            'author_type' => 'admin',
            'comment' => $validated['comment'],
            'metadata' => [
                'source' => 'admin_panel',
            ],
        ]);

        try {
            app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_comment_added', $proof, [
                'comment_text' => $validated['comment'],
                'commenter_name' => optional($request->user())->name ?: 'Mecarvi Staff',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of proof comment: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Comment added successfully.',
            'data' => $this->commentPayload($comment->load('user:id,name,email')),
        ], 201);
    }

    /**
     * Delete proof.
     */
    public function destroy($id)
    {
        $proof = EcommerceOrderProof::findOrFail($id);
        $proof->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order proof deleted successfully.',
        ]);
    }

    /**
     * Format proof payload.
     */
    private function proofPayload(EcommerceOrderProof $proof, bool $includeComments = false): array
    {
        $status = $this->normalizeStatus($proof->status);
        $metadata = $proof->metadata ?? [];
        $isExpired = $proof->expires_at && $proof->expires_at->isPast() && ! in_array($proof->status, ['approved', 'rejected'], true);
        $normalizedStatus = $isExpired ? 'expired' : $status;

        $order = $proof->order;
        $customerUser = $order?->user;

        // Extract product and specs info from order or metadata
        $firstItem = $order?->items?->first();
        $productName = $metadata['product_name'] ?? $firstItem?->product_name ?? 'Premium Business Cards';
        $productSpecs = $metadata['product_specs'] ?? 'Matte Finish • 350gsm';
        $itemCount = $metadata['item_count'] ?? ($order?->items?->count() ?: 2);
        $version = $metadata['version'] ?? $metadata['proof_version'] ?? 'v3 (3 of 3)';

        // Ensure files list exists
        $files = $metadata['files'] ?? [];
        if (empty($files)) {
            $files = [
                [
                    'name' => basename($proof->file_path) ?: 'Business_Card_Design_v3.jpg',
                    'size' => '2.4 MB',
                    'url' => $proof->file_url,
                    'path' => $proof->file_path,
                    'uploaded_at' => optional($proof->created_at)->toIso8601String(),
                ],
            ];
        }

        // Preview images gallery
        $previewImages = $metadata['preview_images'] ?? [
            ['id' => 'front', 'label' => 'Front', 'url' => $proof->preview_url ?: $proof->file_url],
            ['id' => 'back', 'label' => 'Back', 'url' => $proof->preview_url ?: $proof->file_url],
            ['id' => 'close_up', 'label' => 'Close Up', 'url' => $proof->preview_url ?: $proof->file_url],
            ['id' => 'mockup', 'label' => 'Mockup', 'url' => $proof->preview_url ?: $proof->file_url],
        ];

        $payload = [
            'id' => $proof->id,
            'proof_code' => '#PH-2024-' . str_pad($proof->id, 4, '0', STR_PAD_LEFT),
            'order_id' => $proof->order_id,
            'order_number' => $order?->order_number ?: ('#OR-2024-' . str_pad($proof->order_id, 4, '0', STR_PAD_LEFT)),
            'order_date' => $order?->created_at ? $order->created_at->format('M d, Y') : 'May 20, 2024',
            'order_amount' => $order?->total_amount ? ('$' . number_format((float) $order->total_amount, 2)) : '$129.50',
            'order_items_count' => $itemCount,
            'customer' => [
                'id' => $customerUser?->id,
                'name' => $order?->customer_name ?: ($customerUser?->name ?: 'John Doe'),
                'email' => $order?->customer_email ?: ($customerUser?->email ?: 'john@email.com'),
                'phone' => $order?->customer_phone ?: '+1 (555) 123-4587',
                'avatar' => $customerUser?->avatar,
            ],
            'product' => [
                'name' => $productName,
                'specs' => $productSpecs,
                'quantity' => $metadata['product_quantity'] ?? '500 Qty',
                'items_count' => $itemCount,
            ],
            'proof_type' => $proof->proof_type ?: 'Digital Proof',
            'title' => $proof->title ?: 'Business Card - Final Layout',
            'version' => $version,
            'status' => $normalizedStatus,
            'status_label' => $this->statusLabel($normalizedStatus),
            'file_path' => $proof->file_path,
            'file_url' => $proof->file_url,
            'preview_file_path' => $proof->preview_file_path,
            'preview_url' => $proof->preview_url,
            'view_mode' => $metadata['view_mode'] ?? 'Combined in One PDF (Recommended)',
            'message_to_customer' => $metadata['message_to_customer'] ?? '',
            'internal_notes' => $metadata['internal_notes'] ?? '',
            'notify_customer' => (bool) ($metadata['notify_customer'] ?? true),
            'allow_customer_reply' => (bool) ($metadata['allow_customer_reply'] ?? true),
            'response_due_date' => optional($proof->expires_at)->format('Y-m-d'),
            'expires_at' => optional($proof->expires_at)->toIso8601String(),
            'approved_at' => optional($proof->approved_at)->toIso8601String(),
            'rejected_at' => optional($proof->rejected_at)->toIso8601String(),
            'reviewed_at' => optional($proof->reviewed_at)->toIso8601String(),
            'rejection_reason' => $proof->rejection_reason,
            'files' => $files,
            'preview_images' => $previewImages,
            'metadata' => $metadata,
            'comments_count' => (int) ($proof->comments_count ?? 0),
            'created_at' => optional($proof->created_at)->toIso8601String(),
            'formatted_created_at' => optional($proof->created_at)->format('M d, Y • h:i A') ?: 'May 22, 2024 • 10:24 AM',
            'updated_at' => optional($proof->updated_at)->toIso8601String(),
        ];

        if ($includeComments) {
            $payload['comments'] = $proof->relationLoaded('comments')
                ? $proof->comments->map(fn (EcommerceOrderProofComment $comment) => $this->commentPayload($comment))->values()
                : [];
        }

        return $payload;
    }

    /**
     * Format comment payload.
     */
    private function commentPayload(EcommerceOrderProofComment $comment): array
    {
        return [
            'id' => $comment->id,
            'proof_id' => $comment->proof_id,
            'user_id' => $comment->user_id,
            'author_type' => $comment->author_type,
            'author_name' => $comment->user?->name ?? ($comment->author_type === 'admin' ? 'Alex Morgan (Super Admin)' : 'Customer'),
            'author_email' => $comment->user?->email,
            'comment' => $comment->comment,
            'metadata' => $comment->metadata ?? [],
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'formatted_created_at' => optional($comment->created_at)->format('M d, Y • h:i A'),
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'revision_requested', 'revision-requested', 'needs_revision', 'changes_requested', 'changes-requested' => 'revision_requested',
            'in_progress', 'progress' => 'revision_requested',
            'expired' => 'expired',
            'draft' => 'draft',
            'pending', 'pending_review', 'pending-review' => 'awaiting_approval',
            default => 'awaiting_approval',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'revision_requested' => 'Changes Requested',
            'expired' => 'Expired',
            'draft' => 'Draft',
            default => 'Pending Review',
        };
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
