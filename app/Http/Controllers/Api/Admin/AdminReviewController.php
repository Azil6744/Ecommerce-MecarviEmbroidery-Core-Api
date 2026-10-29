<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceReview;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    /**
     * Get all reviews with approval management
     */
    public function index(Request $request)
    {
        $query = EcommerceReview::with('product', 'user');

        if ($request->filled('status') && strtolower($request->status) !== 'all statuses' && strtolower($request->status) !== 'all') {
            $query->whereRaw('LOWER(status) = ?', [strtolower($request->status)]);
        }

        if ($request->filled('rating') && strtolower($request->rating) !== 'all ratings' && strtolower($request->rating) !== 'all') {
            $numRating = (int) $request->rating;
            if ($numRating > 0) {
                $query->where('rating', $numRating);
            }
        }

        if ($request->filled('search')) {
            $term = '%' . strtolower($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(customer_name) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(title) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(comment) LIKE ?', [$term])
                  ->orWhereHas('product', function ($pq) use ($term) {
                      $pq->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(sku) LIKE ?', [$term]);
                  });
            });
        }

        $totalCount = EcommerceReview::count();
        $approvedCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['approved'])->count();
        $pendingCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['pending'])->count();
        $rejectedCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['rejected'])->count();
        $avgRating = round((float) (EcommerceReview::avg('rating') ?: 5.0), 1);
        $positiveRate = $totalCount > 0
            ? round(((EcommerceReview::where('rating', '>=', 4)->count() / $totalCount) * 100), 1)
            : 100.0;

        $stats = [
            'total_reviews' => $totalCount,
            'approved_reviews' => $approvedCount,
            'pending_reviews' => $pendingCount,
            'rejected_reviews' => $rejectedCount,
            'average_rating' => $avgRating,
            'positive_rate' => $positiveRate,
        ];

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
        $totalCount = EcommerceReview::count();
        $approvedCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['approved'])->count();
        $pendingCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['pending'])->count();
        $rejectedCount = EcommerceReview::whereRaw('LOWER(status) = ?', ['rejected'])->count();
        $avgRating = round((float) (EcommerceReview::avg('rating') ?: 5.0), 1);
        $positiveRate = $totalCount > 0
            ? round(((EcommerceReview::where('rating', '>=', 4)->count() / $totalCount) * 100), 1)
            : 100.0;

        return response()->json([
            'total_reviews' => $totalCount,
            'approved_reviews' => $approvedCount,
            'pending_reviews' => $pendingCount,
            'rejected_reviews' => $rejectedCount,
            'average_rating' => $avgRating,
            'positive_rate' => $positiveRate,
        ]);
    }

    /**
     * Show review details
     */
    public function show(EcommerceReview $review)
    {
        return response()->json($review->load('product', 'user'));
    }

    /**
     * Approve/reject review
     */
    public function approve(Request $request, EcommerceReview $review)
    {
        $currentStatus = strtolower(trim((string) $review->status));
        if ($currentStatus === EcommerceReview::STATUS_APPROVED || $currentStatus === EcommerceReview::STATUS_REJECTED || $currentStatus === 'declined') {
            return response()->json([
                'success' => false,
                'message' => 'Review status is final and cannot be modified once approved or declined.'
            ], 422);
        }

        $request->validate([
            'status' => 'required|in:' . EcommerceReview::STATUS_APPROVED . ',' . EcommerceReview::STATUS_REJECTED,
        ]);

        $oldStatus = $review->status;
        $review->update(['status' => $request->status]);

        if ($request->status === EcommerceReview::STATUS_APPROVED && strtolower((string) $oldStatus) !== EcommerceReview::STATUS_APPROVED && $review->user_id) {
            $loyaltyUser = \App\Models\User::find($review->user_id);
            if ($loyaltyUser) {
                \App\Services\LoyaltyService::awardActivity(
                    $review->user_id,
                    'review',
                    'review_reward',
                    'Loyalty points for approved product review.',
                    'review',
                    (string) $review->id
                );
            }
        }

        return response()->json($review);
    }

    /**
     * Delete review
     */
    public function destroy(EcommerceReview $review)
    {
        $review->delete();
        return response()->json(['message' => 'Review deleted successfully']);
    }
}
