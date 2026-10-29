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
        $query = EcommerceReview::with(['product', 'user']);

        if ($request->filled('product_id')) {
            $query->where('product_id', (string) $request->input('product_id'));

            $user = $request->user();
            if (!$user || !$user->isSuperAdmin()) {
                $query->where(function ($q) use ($user) {
                    $q->where('status', EcommerceReview::STATUS_APPROVED)
                      ->orWhere('status', 'active');
                    if ($user) {
                        $q->orWhere('user_id', $user->id);
                    }
                });
            }
            return response()->json(['success' => true, 'data' => $query->latest()->get()]);
        }

        $user = $request->user();
        if ($user && $user->isSuperAdmin()) {
            return response()->json(['success' => true, 'data' => $query->latest()->get()]);
        }
        
        if ($user && Schema::hasColumn((new EcommerceReview)->getTable(), 'user_id')) {
            $query->where('user_id', $user->id);
            return response()->json(['success' => true, 'data' => $query->latest()->get()]);
        }

        return response()->json(['success' => true, 'data' => $query->latest()->get()]);
    }

    public function canReview(Request $request, $productId)
    {
        $user = $request->user();
        $email = $request->input('email') ?? ($user ? $user->email : null);

        if (!$user && empty($email)) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'unauthenticated',
                'message' => 'Please sign in to leave a review for this product.'
            ]);
        }

        $completedStatuses = ['completed', 'delivered', 'complete'];

        $orderQuery = EcommerceOrder::query()
            ->where(function ($oq) use ($user, $email) {
                if ($user) {
                    $oq->where('user_id', $user->id);
                }
                if (!empty($email)) {
                    $oq->orWhere('customer_email', strtolower(trim($email)));
                }
            })
            ->where(function ($sq) use ($completedStatuses) {
                $sq->whereIn('order_status', $completedStatuses)
                   ->orWhereIn('status', $completedStatuses);
            })
            ->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId)
                  ->orWhere('product_sku', $productId);
            });

        $hasPurchased = $orderQuery->exists();

        if (!$hasPurchased) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'not_purchased_completed',
                'message' => 'Customers can only leave a review after purchasing this product and the order status is updated to complete or delivered.'
            ]);
        }

        $alreadyReviewedQuery = EcommerceReview::query()
            ->where('product_id', (string) $productId);

        if ($user) {
            $alreadyReviewedQuery->where('user_id', $user->id);
        } elseif (!empty($email)) {
            $alreadyReviewedQuery->where('customer_email', $email);
        }

        $alreadyReviewed = $alreadyReviewedQuery->exists();

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
        $email = $request->input('customer_email') ?? ($user ? $user->email : null);

        if (!$user && empty($email)) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $productId = $request->input('product_id');
        $completedStatuses = ['completed', 'delivered', 'complete'];

        $hasPurchased = EcommerceOrder::query()
            ->where(function ($oq) use ($user, $email) {
                if ($user) {
                    $oq->where('user_id', $user->id);
                }
                if (!empty($email)) {
                    $oq->orWhere('customer_email', strtolower(trim($email)));
                }
            })
            ->where(function ($sq) use ($completedStatuses) {
                $sq->whereIn('order_status', $completedStatuses)
                   ->orWhereIn('status', $completedStatuses);
            })
            ->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId)
                  ->orWhere('product_sku', $productId);
            })
            ->exists();

        if (!$hasPurchased && !($user && $user->isSuperAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Customers can only leave a review after purchasing this product and the order status is updated to complete or delivered.'
            ], 403);
        }

        $data = $request->all();
        if ($user) {
            $data['user_id'] = $user->id;
        }
        $data['status'] = EcommerceReview::STATUS_PENDING;
        $item = EcommerceReview::create($data);
        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully and is pending admin approval.',
            'data' => $item
        ]);
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
            'order_number' => ['nullable', 'string', 'max:255'],
        ]);

        $productId = (string) $validated['product_id'];

        // If product ID is a string name or sku, try finding the actual product
        if (!is_numeric($productId)) {
            $matchedProduct = \App\Models\Product::where('id', $productId)
                ->orWhere('sku', $productId)
                ->orWhere('name', 'like', "%{$productId}%")
                ->first();
            if ($matchedProduct) {
                $productId = (string) $matchedProduct->id;
            }
        }

        $email = !empty($validated['customer_email']) ? strtolower(trim($validated['customer_email'])) : ($user ? $user->email : null);
        $orderNumber = $validated['order_number'] ?? null;
        $completedStatuses = ['completed', 'delivered', 'complete'];

        // Verify purchase and completed/delivered status
        $orderQuery = EcommerceOrder::query()
            ->where(function ($sq) use ($completedStatuses) {
                $sq->whereIn('order_status', $completedStatuses)
                   ->orWhereIn('status', $completedStatuses);
            });

        if ($user) {
            $orderQuery->where(function ($q) use ($user, $email, $orderNumber) {
                $q->where('user_id', $user->id);
                if (!empty($email)) {
                    $q->orWhere('customer_email', $email);
                }
                if (!empty($orderNumber)) {
                    $cleanNum = ltrim($orderNumber, '#');
                    $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                }
            });
        } elseif (!empty($email) || !empty($orderNumber)) {
            $orderQuery->where(function ($q) use ($email, $orderNumber) {
                if (!empty($email)) {
                    $q->where('customer_email', $email);
                }
                if (!empty($orderNumber)) {
                    $cleanNum = ltrim($orderNumber, '#');
                    $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                }
            });
        }

        $orderQuery->whereHas('items', function ($q) use ($productId) {
            $q->where('product_id', $productId)
              ->orWhere('product_sku', $productId);
        });

        $hasPurchased = $orderQuery->exists();

        if (!$hasPurchased && !($user && $user->isSuperAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Customers can only leave a review after purchasing this product and the order status is updated to complete or delivered.'
            ], 403);
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

        // Always save as STATUS_PENDING so admin must review and approve/decline
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
            'message' => 'Review submitted successfully and is pending admin approval.',
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

