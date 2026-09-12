<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceReview;
use App\Models\EcommerceOrder;
use Illuminate\Support\Facades\Schema;

class EcommerceReviewController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user && $user->isSuperAdmin()) {
            return response()->json(['success' => true, 'data' => EcommerceReview::with('product')->get()]);
        }
        
        if ($user && Schema::hasColumn((new EcommerceReview)->getTable(), 'user_id')) {
            $query = EcommerceReview::with('product')->where('user_id', $user->id);
            return response()->json(['success' => true, 'data' => $query->get()]);
        }

        return response()->json(['success' => true, 'data' => EcommerceReview::with('product')->get()]);
    }

    public function canReview(Request $request, $productId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'unauthenticated',
                'message' => 'Please log in to write a review.'
            ]);
        }

        $hasPurchased = EcommerceOrder::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereIn('payment_status', ['paid', 'completed'])
                  ->orWhereIn('order_status', ['completed', 'delivered', 'processing']);
            })
            ->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            })
            ->exists();

        if (!$hasPurchased) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'not_purchased',
                'message' => 'Only customers who have purchased this product can leave a review.'
            ]);
        }

        $alreadyReviewed = EcommerceReview::where('user_id', $user->id)
            ->where('product_id', (string) $productId)
            ->exists();

        return response()->json([
            'success' => true,
            'can_review' => true,
            'already_reviewed' => $alreadyReviewed,
            'message' => $alreadyReviewed ? 'You have already submitted a review for this product.' : 'You can review this product.'
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $productId = $request->input('product_id');
        $hasPurchased = EcommerceOrder::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereIn('payment_status', ['paid', 'completed'])
                  ->orWhereIn('order_status', ['completed', 'delivered', 'processing']);
            })
            ->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            })
            ->exists();

        if (!$hasPurchased && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only verified buyers who have purchased this product can leave a review.'
            ], 403);
        }

        $data = $request->all();
        $data['user_id'] = $user->id;
        $data['status'] = EcommerceReview::STATUS_PENDING;
        $item = EcommerceReview::create($data);
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function publicStore(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);

        $productId = (string) $validated['product_id'];

        // If product ID is a string name or slug, try finding the actual product
        if (!is_numeric($productId)) {
            $matchedProduct = \App\Models\Product::where('id', $productId)
                ->orWhere('sku', $productId)
                ->orWhere('name', 'like', "%{$productId}%")
                ->first();
            if ($matchedProduct) {
                $productId = (string) $matchedProduct->id;
            }
        }

        $customerName = null;
        if ($user && !empty($user->name)) {
            $customerName = $user->name;
        } elseif (!empty($validated['customer_name']) && $validated['customer_name'] !== 'Verified Customer') {
            $customerName = $validated['customer_name'];
        } elseif ($user && !empty($user->email)) {
            $customerName = $user->email;
        } else {
            $customerName = !empty($validated['customer_name']) ? $validated['customer_name'] : 'Customer';
        }

        $review = EcommerceReview::create([
            'product_id' => $productId,
            'user_id' => $user ? $user->id : null,
            'customer_name' => $customerName,
            'rating' => (int) $validated['rating'],
            'title' => !empty($validated['title']) ? $validated['title'] : ($validated['rating'] >= 4 ? 'Great Quality!' : 'Customer Review'),
            'comment' => $validated['comment'] ?? '',
            'status' => EcommerceReview::STATUS_PENDING,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully and is pending approval.',
            'data' => $review->load('product')
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $item = EcommerceReview::with('product', 'user')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function update(Request $request, $id)
    {
        $item = EcommerceReview::findOrFail($id);
        $item->update($request->all());
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy(Request $request, $id)
    {
        $item = EcommerceReview::findOrFail($id);
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }
}

