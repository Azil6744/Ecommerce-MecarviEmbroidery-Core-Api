<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceReview;
use App\Models\EcommerceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class EcommerceReviewController extends Controller
{
    public function index(Request $request)
    {
        $query = EcommerceReview::with(['product.category', 'user', 'order']);

        if ($request->filled('product_id')) {
            $query->where('product_id', (string) $request->input('product_id'));

            if (! $request->boolean('include_pending')) {
                $query->whereRaw("LOWER(status) IN ('approved', 'active')");
            }

            return response()->json([
                'success' => true,
                'data' => $query->latest('id')->get(),
            ]);
        }

        $user = $request->user();
        $hasEmailColumn = Schema::hasColumn((new EcommerceReview)->getTable(), 'customer_email');
        $hasOrderNumColumn = Schema::hasColumn((new EcommerceReview)->getTable(), 'order_number');

        $customerEmail = $request->input('customer_email');
        if (empty($customerEmail) && $user && ! empty($user->email)) {
            $customerEmail = $user->email;
        }

        $customerName = $request->input('customer_name');
        if (empty($customerName) && $user && ! empty($user->name)) {
            $customerName = $user->name;
        }

        $userId = $request->input('user_id') ?? ($user ? $user->id : null);
        $orderNumber = $request->input('order_number');

        if ($request->boolean('strict_user') || $request->boolean('my_reviews')) {
            if (! $user && empty($userId) && empty($customerEmail) && empty($customerName) && empty($orderNumber)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($user, $userId, $customerEmail, $customerName, $orderNumber, $hasEmailColumn, $hasOrderNumColumn) {
                    if ($user || ! empty($userId)) {
                        $targetId = $user ? $user->id : $userId;
                        $q->orWhere('user_id', $targetId);
                    }
                    if (! empty($customerEmail) && $hasEmailColumn) {
                        $cleanEmail = strtolower(trim((string) $customerEmail));
                        $q->orWhereRaw('LOWER(customer_email) = ?', [$cleanEmail]);
                    }
                    if (! empty($customerName)) {
                        $cleanName = strtolower(trim((string) $customerName));
                        $q->orWhereRaw('LOWER(customer_name) = ?', [$cleanName]);
                    }
                    if (! empty($orderNumber) && $hasOrderNumColumn) {
                        $cleanNum = ltrim(trim((string) $orderNumber), '#');
                        $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                    }
                });
            }
        } elseif ($user || ! empty($userId) || ! empty($customerEmail) || ! empty($customerName) || ! empty($orderNumber)) {
            $query->where(function ($q) use ($user, $userId, $customerEmail, $customerName, $orderNumber, $hasEmailColumn, $hasOrderNumColumn) {
                if ($user || ! empty($userId)) {
                    $targetId = $user ? $user->id : $userId;
                    $q->orWhere('user_id', $targetId);
                }
                if (! empty($customerEmail) && $hasEmailColumn) {
                    $cleanEmail = strtolower(trim((string) $customerEmail));
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$cleanEmail]);
                }
                if (! empty($customerName)) {
                    $cleanName = strtolower(trim((string) $customerName));
                    $q->orWhereRaw('LOWER(customer_name) = ?', [$cleanName]);
                }
                if (! empty($orderNumber) && $hasOrderNumColumn) {
                    $cleanNum = ltrim(trim((string) $orderNumber), '#');
                    $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                }
            });
        }

        if ($request->filled('status') && strtolower((string) $request->input('status')) !== 'all') {
            $status = strtolower((string) $request->input('status'));
            if ($status === 'declined') {
                $status = 'rejected';
            }
            $query->whereRaw('LOWER(status) = ?', [$status]);
        }

        if ($request->filled('search')) {
            $term = '%' . strtolower(trim((string) $request->input('search'))) . '%';
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

        if ($request->has('per_page')) {
            $perPage = max(1, min(100, (int) $request->input('per_page', 10)));
            $paginated = $query->latest('id')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function canReview(Request $request, $productId)
    {
        $user = $request->user();
        $email = $request->input('email') ?? ($user ? $user->email : null);

        $completedStatuses = ['completed', 'delivered', 'complete', 'processing', 'shipped'];
        $hasPurchased = false;

        if ($user || ! empty($email)) {
            try {
                $orderQuery = EcommerceOrder::query()
                    ->where(function ($oq) use ($user, $email) {
                        if ($user) {
                            $oq->where('user_id', $user->id);
                        }
                        if (! empty($email)) {
                            $oq->orWhere('customer_email', strtolower(trim($email)));
                        }
                    })
                    ->where(function ($sq) use ($completedStatuses) {
                        $sq->whereIn('status', $completedStatuses);
                    })
                    ->whereHas('items', function ($q) use ($productId) {
                        $q->where('product_id', $productId)
                          ->orWhere('product_sku', $productId);
                    });

                $hasPurchased = $orderQuery->exists();
            } catch (\Throwable $e) {
                $hasPurchased = false;
            }
        }

        $alreadyReviewed = false;
        if ($user || ! empty($email)) {
            $alreadyReviewedQuery = EcommerceReview::query()
                ->where('product_id', (string) $productId);

            if ($user) {
                $alreadyReviewedQuery->where('user_id', $user->id);
            } elseif (! empty($email) && Schema::hasColumn((new EcommerceReview)->getTable(), 'customer_email')) {
                $alreadyReviewedQuery->whereRaw('LOWER(customer_email) = ?', [strtolower(trim($email))]);
            }

            $alreadyReviewed = $alreadyReviewedQuery->exists();
        }

        return response()->json([
            'success' => true,
            'can_review' => true,
            'verified_buyer' => $hasPurchased,
            'already_reviewed' => $alreadyReviewed,
            'message' => $alreadyReviewed ? 'You have already submitted a review for this product.' : 'You can review this product.',
        ]);
    }

    public function store(Request $request)
    {
        return $this->publicStore($request);
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
            'images' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ]);

        $productId = (string) $validated['product_id'];
        $productName = $request->input('product_name');

        // Ensure productId maps to an existing product
        $existingProduct = is_numeric($productId)
            ? \App\Models\Product::find((int) $productId)
            : \App\Models\Product::where('sku', $productId)->first();

        if (! $existingProduct && ! empty($productName)) {
            $existingProduct = \App\Models\Product::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower(trim((string) $productName)) . '%'])->first();
        }
        if (! $existingProduct && ! is_numeric($productId)) {
            $existingProduct = \App\Models\Product::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower(trim($productId)) . '%'])->first();
        }
        if (! $existingProduct) {
            $existingProduct = \App\Models\Product::first();
        }
        if ($existingProduct) {
            $productId = (string) $existingProduct->id;
        }

        $reqUserId = $request->input('user_id');
        if (! $user && ! empty($reqUserId)) {
            $user = User::find($reqUserId);
        }

        $email = ! empty($validated['customer_email'])
            ? strtolower(trim($validated['customer_email']))
            : ($user ? $user->email : null);

        if (! $user && ! empty($email)) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        $orderNumber = $validated['order_number'] ?? null;
        $completedStatuses = ['completed', 'delivered', 'complete', 'processing', 'shipped', 'pending'];
        $hasPurchased = ! empty($orderNumber);

        if ($user || ! empty($email) || ! empty($orderNumber)) {
            try {
                $orderQuery = EcommerceOrder::query()
                    ->where(function ($sq) use ($completedStatuses) {
                        $sq->whereIn('status', $completedStatuses);
                    });

                if ($user) {
                    $orderQuery->where(function ($q) use ($user, $email, $orderNumber) {
                        $q->where('user_id', $user->id);
                        if (! empty($email)) {
                            $q->orWhere('customer_email', $email);
                        }
                        if (! empty($orderNumber)) {
                            $cleanNum = ltrim($orderNumber, '#');
                            $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                        }
                    });
                } else {
                    $orderQuery->where(function ($q) use ($email, $orderNumber) {
                        if (! empty($email)) {
                            $q->where('customer_email', $email);
                        }
                        if (! empty($orderNumber)) {
                            $cleanNum = ltrim($orderNumber, '#');
                            $q->orWhere('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                        }
                    });
                }

                $orderQuery->whereHas('items', function ($q) use ($productId) {
                    $q->where('product_id', $productId)
                      ->orWhere('product_sku', $productId);
                });

                if ($orderQuery->exists()) {
                    $hasPurchased = true;
                }
            } catch (\Throwable $e) {
                // If query fails, fall back to purchase flag if orderNumber was provided
                if (! empty($orderNumber)) {
                    $hasPurchased = true;
                }
            }
        }

        if (! empty($validated['customer_name']) && $validated['customer_name'] !== 'Verified Customer' && $validated['customer_name'] !== 'Customer') {
            $customerName = $validated['customer_name'];
        } elseif ($user && ! empty($user->name)) {
            $customerName = $user->name;
        } elseif ($user && ! empty($user->email)) {
            $customerName = $user->email;
        } else {
            $customerName = ! empty($validated['customer_name']) ? $validated['customer_name'] : 'Customer';
        }

        $images = $validated['images'] ?? $validated['photos'] ?? null;

        $reviewData = [
            'product_id' => $productId,
            'user_id' => $user ? $user->id : null,
            'customer_name' => $customerName,
            'rating' => (int) $validated['rating'],
            'title' => ! empty($validated['title']) ? $validated['title'] : ($validated['rating'] >= 4 ? 'Great Quality!' : 'Customer Review'),
            'comment' => $validated['comment'] ?? '',
            'status' => EcommerceReview::STATUS_PENDING,
        ];

        $table = (new EcommerceReview)->getTable();
        if (Schema::hasColumn($table, 'customer_email')) {
            $reviewData['customer_email'] = $email;
        }
        if (Schema::hasColumn($table, 'order_number')) {
            $reviewData['order_number'] = $orderNumber;
        }
        if (Schema::hasColumn($table, 'verified_buyer')) {
            $reviewData['verified_buyer'] = $hasPurchased;
        }
        if (Schema::hasColumn($table, 'images') && is_array($images)) {
            $reviewData['images'] = array_values(array_filter($images));
        }

        // Always save as STATUS_PENDING so admin must review and approve/decline
        $review = EcommerceReview::create($reviewData);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully and is pending admin approval.',
            'data' => $review->load(['product', 'user']),
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
        $item->update($request->only(['rating', 'title', 'comment', 'customer_name', 'customer_email', 'images']));
        return response()->json(['success' => true, 'data' => $item->load(['product', 'user'])]);
    }

    public function destroy(Request $request, $id)
    {
        $item = EcommerceReview::findOrFail($id);
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }
}
