<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminReviewController extends Controller
{
    /**
     * Get all reviews with approval management
     */
    public function index(Request $request)
    {
        $query = EcommerceReview::with([
            'product.category',
            'user' => function ($q) {
                $q->withCount(['orders', 'reviews']);
            },
            'order.items',
        ]);

        if ($request->filled('status') && strtolower($request->status) !== 'all statuses' && strtolower($request->status) !== 'all') {
            $statusFilter = strtolower(trim((string) $request->status));
            if ($statusFilter === 'declined' || $statusFilter === 'rejected') {
                $query->whereRaw("LOWER(status) IN ('rejected', 'declined')");
            } else {
                $query->whereRaw('LOWER(status) = ?', [$statusFilter]);
            }
        }

        if ($request->filled('rating') && strtolower($request->rating) !== 'all ratings' && strtolower($request->rating) !== 'all') {
            $numRating = (int) $request->rating;
            if ($numRating > 0) {
                $query->where('rating', $numRating);
            }
        }

        if ($request->filled('search')) {
            $term = '%' . strtolower(trim((string) $request->search)) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(customer_name) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(comment, \'\')) LIKE ?', [$term])
                  ->orWhereHas('product', function ($pq) use ($term) {
                      $pq->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$term]);
                  });
            });
        }

        $stats = $this->computeStats();

        $perPage = (int) $request->get('per_page', 50);
        $reviews = $query->latest('id')->paginate($perPage);

        $res = $reviews->toArray();
        $res['stats'] = $stats;

        return response()->json($res);
    }

    /**
     * Get aggregate review stats
     */
    public function stats()
    {
        return response()->json($this->computeStats());
    }

    protected function computeStats(): array
    {
        $totalCount = EcommerceReview::count();
        $approvedCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['approved'])->count();
        $pendingCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['pending'])->count();
        $rejectedCount = EcommerceReview::whereRaw("LOWER(status) IN ('rejected', 'declined')")->count();
        $avgRating = round((float) (EcommerceReview::avg('rating') ?: 5.0), 1);
        $positiveRate = $totalCount > 0
            ? round(((EcommerceReview::where('rating', '>=', 4)->count() / $totalCount) * 100), 1)
            : 100.0;

        return [
            'total_reviews' => $totalCount,
            'approved_reviews' => $approvedCount,
            'pending_reviews' => $pendingCount,
            'unapproved_reviews' => $pendingCount,
            'rejected_reviews' => $rejectedCount,
            'declined_reviews' => $rejectedCount,
            'average_rating' => $avgRating,
            'positive_rate' => $positiveRate,
        ];
    }

    /**
     * Show review details
     */
    public function show(EcommerceReview $review)
    {
        return response()->json($review->load([
            'product.category',
            'user' => function ($q) {
                $q->withCount(['orders', 'reviews']);
            },
            'order.items',
        ]));
    }

    /**
     * Approve/reject review or update admin reply
     */
    public function approve(Request $request, EcommerceReview $review)
    {
        $request->validate([
            'status' => 'nullable|string|in:approved,rejected,declined,pending',
            'admin_reply' => 'nullable|string',
            'reply' => 'nullable|string',
        ]);

        $oldStatus = strtolower(trim((string) $review->status));
        $updates = [];

        if ($request->filled('status')) {
            $newStatus = strtolower(trim((string) $request->input('status')));
            if ($newStatus === 'declined') {
                $newStatus = EcommerceReview::STATUS_REJECTED;
            }
            $updates['status'] = $newStatus;
        }

        if ($request->has('admin_reply') || $request->has('reply')) {
            $replyText = $request->input('admin_reply') ?? $request->input('reply');
            if (Schema::hasColumn($review->getTable(), 'admin_reply')) {
                $updates['admin_reply'] = $replyText;
            }
            if (Schema::hasColumn($review->getTable(), 'admin_reply_at')) {
                $updates['admin_reply_at'] = ! empty($replyText) ? now() : null;
            }
        }

        if (! empty($updates)) {
            $review->update($updates);
        }

        if (($updates['status'] ?? null) === EcommerceReview::STATUS_APPROVED && $oldStatus !== EcommerceReview::STATUS_APPROVED && $review->user_id) {
            try {
                $loyaltyUser = \App\Models\User::find($review->user_id);
                if ($loyaltyUser) {
                    \App\Services\LoyaltyService::awardActivity(
                        (int) $review->user_id,
                        'review',
                        'review_reward',
                        'Loyalty points for approved product review.',
                        'review',
                        (string) $review->id
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('AdminReviewController loyalty award failed: ' . $e->getMessage());
            }
        }

        return response()->json($review->fresh(['product', 'user']));
    }

    /**
     * Save admin reply to a customer review
     */
    public function reply(Request $request, EcommerceReview $review)
    {
        $validated = $request->validate([
            'admin_reply' => 'nullable|string',
            'reply' => 'nullable|string',
            'content' => 'nullable|string',
        ]);

        $replyText = $validated['admin_reply'] ?? $validated['reply'] ?? $validated['content'] ?? '';

        $updates = [];
        if (Schema::hasColumn($review->getTable(), 'admin_reply')) {
            $updates['admin_reply'] = $replyText;
        }
        if (Schema::hasColumn($review->getTable(), 'admin_reply_at')) {
            $updates['admin_reply_at'] = ! empty($replyText) ? now() : null;
        }

        if (! empty($updates)) {
            $review->update($updates);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reply saved successfully.',
            'data' => $review->fresh(['product', 'user']),
        ]);
    }

    /**
     * Delete review
     */
    public function destroy(EcommerceReview $review)
    {
        $review->delete();
        return response()->json(['success' => true, 'message' => 'Review deleted successfully']);
    }
}
