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
        $query = EcommerceReview::with(['product', 'user']);

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

        if ($user || $request->filled('customer_email') || $request->filled('customer_name')) {
            $userMatchedQuery = (clone $query)->where(function ($q) use ($user, $request, $hasEmailColumn) {
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                    if (! empty($user->email) && $hasEmailColumn) {
                        $q->orWhereRaw('LOWER(customer_email) = ?', [strtolower(trim($user->email))]);
                    }
                    if (! empty($user->name)) {
                        $q->orWhereRaw('LOWER(customer_name) = ?', [strtolower(trim($user->name))]);
                    }
                }
                if ($request->filled('customer_email') && $hasEmailColumn) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [strtolower(trim((string) $request->input('customer_email')))]);
                }
                if ($request->filled('customer_name')) {
                    $q->orWhereRaw('LOWER(customer_name) = ?', [strtolower(trim((string) $request->input('customer_name')))]);
                }
            });

            if ($userMatchedQuery->exists() || $request->boolean('strict_user')) {
                $query = $userMatchedQuery;
            }
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

        $completedStatuses = ['completed', 'delivered', 'complete'];
        $hasPurchased = false;

        if ($user || ! empty($email)) {
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
                    $sq->whereIn('order_status', $completedStatuses)
                       ->orWhereIn('status', $completedStatuses);
                })
                ->whereHas('items', function ($q) use ($productId) {
                    $q->where('product_id', $productId)
                      ->orWhere('product_sku', $productId);
                });

            $hasPurchased = $orderQuery->exists();
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

        $email = ! empty($validated['customer_email'])
            ? strtolower(trim($validated['customer_email']))
            : ($user ? $user->email : null);

        if (! $user && ! empty($email)) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        $orderNumber = $validated['order_number'] ?? null;
        $completedStatuses = ['completed', 'delivered', 'complete'];
        $hasPurchased = false;

        if ($user || ! empty($email) || ! empty($orderNumber)) {
            $orderQuery = EcommerceOrder::query()
                ->where(function ($sq) use ($completedStatuses) {
                    $sq->whereIn('order_status', $completedStatuses)
                       ->orWhereIn('status', $completedStatuses);
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

            $hasPurchased = $orderQuery->exists();
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
