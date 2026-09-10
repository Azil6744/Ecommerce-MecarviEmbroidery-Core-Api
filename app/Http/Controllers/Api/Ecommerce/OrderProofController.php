<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrderProof;
use App\Models\EcommerceOrderProofComment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderProofController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:80'],
            'proof_type' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $baseQuery = $this->ownedProofQuery($request);

        if (! empty($validated['search'])) {
            $search = '%' . trim($validated['search']) . '%';
            $baseQuery->where(function ($query) use ($search) {
                $query->where('proof_type', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhere('rejection_reason', 'like', $search)
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', $search)
                            ->orWhere('customer_name', 'like', $search);
                    });
            });
        }

        if (! empty($validated['status']) && strtolower($validated['status']) !== 'all') {
            $status = $this->normalizeStatus($validated['status']);
            if ($status === 'expired') {
                $baseQuery->whereNotIn('status', ['approved', 'rejected'])
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now());
            } elseif ($status === 'revision_requested') {
                $baseQuery->whereIn('status', ['revision_requested', 'rejected']);
            } else {
                $baseQuery->where('status', $status);
            }
        }

        if (! empty($validated['proof_type']) && strtolower($validated['proof_type']) !== 'all') {
            $baseQuery->where('proof_type', $validated['proof_type']);
        }

        $sort = strtolower($validated['sort'] ?? 'newest');
        if ($sort === 'oldest') {
            $baseQuery->oldest();
        } else {
            $baseQuery->latest();
        }

        $proofs = (clone $baseQuery)
            ->with(['order:id,order_number,user_id,customer_name', 'comments.user:id,name,email'])
            ->withCount('comments')
            ->paginate((int) ($validated['per_page'] ?? 9));

        $countsQuery = $this->ownedProofQuery($request)->get(['status', 'expires_at']);
        $expiredCount = $countsQuery->filter(function (EcommerceOrderProof $proof) {
            return $proof->expires_at && $proof->expires_at->isPast() && ! in_array($proof->status, ['approved', 'rejected'], true);
        })->count();
        $awaitingCount = $countsQuery->filter(function (EcommerceOrderProof $proof) {
            return $proof->status === 'awaiting_approval'
                && (! $proof->expires_at || $proof->expires_at->isFuture());
        })->count();

        return response()->json([
            'success' => true,
            'data' => $proofs->getCollection()->map(fn (EcommerceOrderProof $proof) => $this->proofPayload($proof))->values(),
            'meta' => [
                'current_page' => $proofs->currentPage(),
                'last_page' => $proofs->lastPage(),
                'per_page' => $proofs->perPage(),
                'total' => $proofs->total(),
                'from' => $proofs->firstItem() ?? 0,
                'to' => $proofs->lastItem() ?? 0,
                'counts' => [
                    'all' => $countsQuery->count(),
                    'awaiting_approval' => $awaitingCount,
                    'approved' => $countsQuery->where('status', 'approved')->count(),
                    'rejected' => $countsQuery->where('status', 'rejected')->count(),
                    'revision_requested' => $countsQuery->where('status', 'revision_requested')->count(),
                    'expired' => $expiredCount,
                ],
            ],
        ]);
    }

    public function getByOrder(Request $request, $orderId)
    {
        $user = $request->user();

        $query = EcommerceOrderProof::query()
            ->where('order_id', $orderId)
            ->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['order:id,order_number,user_id,customer_name', 'comments.user:id,name,email'])
            ->withCount('comments')
            ->latest();

        $proofs = $query->get();

        return response()->json([
            'success' => true,
            'data' => $proofs->map(fn (EcommerceOrderProof $proof) => $this->proofPayload($proof))->values(),
            'meta' => [
                'total' => $proofs->count(),
                'counts' => [
                    'awaiting_approval' => $proofs->where('status', 'awaiting_approval')->count(),
                    'approved' => $proofs->where('status', 'approved')->count(),
                    'rejected' => $proofs->where('status', 'rejected')->count(),
                    'revision_requested' => $proofs->where('status', 'revision_requested')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $proof = $this->ownedProofQuery($request)
            ->with(['order:id,order_number,user_id,customer_name,customer_email,customer_phone', 'comments.user:id,name,email'])
            ->withCount('comments')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->proofPayload($proof, true),
        ]);
    }

    public function comments(Request $request, $id)
    {
        $proof = $this->ownedProofQuery($request)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $proof->comments()
                ->with('user:id,name,email')
                ->latest()
                ->get()
                ->map(fn (EcommerceOrderProofComment $comment) => $this->commentPayload($comment))
                ->values(),
        ]);
    }

    public function addComment(Request $request, $id)
    {
        $proof = $this->ownedProofQuery($request)->findOrFail($id);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $proof->comments()->create([
            'user_id' => $request->user()->id,
            'author_type' => 'customer',
            'comment' => $validated['comment'],
            'metadata' => [
                'source' => 'user_panel',
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Comment added successfully.',
            'data' => $this->commentPayload($comment->load('user:id,name,email')),
        ], 201);
    }

    public function approve(Request $request, $id)
    {
        $proof = $this->ownedProofQuery($request)->findOrFail($id);

        $proof->update([
            'status' => 'approved',
            'approved_at' => now(),
            'reviewed_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        try {
            app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_approved', $proof);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify proof approval: ' . $e->getMessage());
        }

        if ($proof->order) {
            $proof->order->recordStatusEvent('proof_approved', 'Customer approved design proof: ' . $proof->title);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proof approved successfully.',
            'data' => $this->proofPayload($proof->fresh(['order', 'comments.user'])->loadCount('comments'), true),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $proof = $this->ownedProofQuery($request)->findOrFail($id);

        $proof->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'reviewed_at' => now(),
            'approved_at' => null,
            'rejection_reason' => $validated['reason'],
        ]);

        try {
            app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_rejected', $proof, [
                'rejection_reason' => $validated['reason'],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify proof rejection: ' . $e->getMessage());
        }

        if ($proof->order) {
            $proof->order->recordStatusEvent('proof_rejected', 'Customer rejected design proof: ' . $proof->title, $validated['reason']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proof rejected. We will update it soon.',
            'data' => $this->proofPayload($proof->fresh(['order', 'comments.user'])->loadCount('comments'), true),
        ]);
    }

    public function requestRevision(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $proof = $this->ownedProofQuery($request)->findOrFail($id);

        $proof->update([
            'status' => 'revision_requested',
            'reviewed_at' => now(),
            'approved_at' => null,
            'rejected_at' => null,
            'rejection_reason' => $validated['reason'],
        ]);

        $proof->comments()->create([
            'user_id' => $request->user()->id,
            'author_type' => 'customer',
            'comment' => $validated['reason'],
            'metadata' => ['kind' => 'revision_request', 'source' => 'user_panel'],
        ]);

        try {
            app(\App\Services\EmailNotificationService::class)->sendProofEvent('order_proof_revision_requested', $proof, [
                'revision_notes' => $validated['reason'],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify proof revision request: ' . $e->getMessage());
        }

        if ($proof->order) {
            $proof->order->recordStatusEvent('proof_revision', 'Customer requested revisions on proof: ' . $proof->title, $validated['reason']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Revision request sent successfully.',
            'data' => $this->proofPayload($proof->fresh(['order', 'comments.user'])->loadCount('comments'), true),
        ]);
    }

    private function ownedProofQuery(Request $request)
    {
        $user = $request->user();

        return EcommerceOrderProof::query()
            ->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
    }

    private function proofPayload(EcommerceOrderProof $proof, bool $includeComments = false): array
    {
        $status = $this->normalizeStatus($proof->status);
        $metadata = $proof->metadata ?? [];
        $isExpired = $proof->expires_at && $proof->expires_at->isPast() && ! in_array($proof->status, ['approved', 'rejected'], true);
        $normalizedStatus = $isExpired ? 'expired' : $status;

        $payload = [
            'id' => $proof->id,
            'order_id' => $proof->order_id,
            'order_number' => $proof->order?->order_number,
            'customer_name' => $proof->order?->customer_name,
            'proof_type' => $proof->proof_type,
            'title' => $proof->title ?: $proof->proof_type,
            'status' => $normalizedStatus,
            'status_label' => $this->statusLabel($normalizedStatus),
            'file_path' => $proof->file_path,
            'file_url' => $proof->file_url,
            'preview_file_path' => $proof->preview_file_path,
            'preview_url' => $proof->preview_url,
            'rejection_reason' => $proof->rejection_reason,
            'metadata' => $metadata,
            'placement' => $metadata['placement'] ?? $metadata['product_area'] ?? null,
            'product_type' => $metadata['product_type'] ?? $metadata['product'] ?? null,
            'art_code' => $metadata['art_code'] ?? null,
            'comments_count' => (int) ($proof->comments_count ?? 0),
            'expires_at' => optional($proof->expires_at)->toIso8601String(),
            'approved_at' => optional($proof->approved_at)->toIso8601String(),
            'rejected_at' => optional($proof->rejected_at)->toIso8601String(),
            'reviewed_at' => optional($proof->reviewed_at)->toIso8601String(),
            'created_at' => optional($proof->created_at)->toIso8601String(),
            'updated_at' => optional($proof->updated_at)->toIso8601String(),
        ];

        if ($includeComments) {
            $payload['comments'] = $proof->relationLoaded('comments')
                ? $proof->comments->map(fn (EcommerceOrderProofComment $comment) => $this->commentPayload($comment))->values()
                : [];
        }

        return $payload;
    }

    private function commentPayload(EcommerceOrderProofComment $comment): array
    {
        return [
            'id' => $comment->id,
            'proof_id' => $comment->proof_id,
            'user_id' => $comment->user_id,
            'author_type' => $comment->author_type,
            'author_name' => $comment->user?->name ?? Str::title(str_replace('_', ' ', $comment->author_type)),
            'author_email' => $comment->user?->email,
            'comment' => $comment->comment,
            'metadata' => $comment->metadata ?? [],
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'updated_at' => optional($comment->updated_at)->toIso8601String(),
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'revision_requested', 'revision-requested', 'needs_revision' => 'revision_requested',
            'in_progress', 'progress' => 'revision_requested',
            'expired' => 'expired',
            default => 'awaiting_approval',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'revision_requested' => 'Revision Requested',
            'expired' => 'Expired',
            default => 'Awaiting Approval',
        };
    }
}
