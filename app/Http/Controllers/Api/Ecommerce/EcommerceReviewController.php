<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceReview;
use App\Models\EcommerceOrder;
use App\Models\User;
use App\Models\Product;
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

    /**
     * Order statuses that make a purchase eligible for a review.
     * A customer can only review a product once the order is completed/delivered in admin.
     */
    private const REVIEWABLE_ORDER_STATUSES = ['completed', 'complete', 'delivered'];

    /**
     * Resolve the product a review refers to (by id or SKU). Returns null when it does not exist.
     */
    private function resolveProduct($productRef, ?string $productName = null): ?Product
    {
        $productRef = trim((string) $productRef);
        $product = null;

        if ($productRef !== '' && ctype_digit($productRef)) {
            $product = Product::find((int) $productRef);
        }
        if (! $product && $productRef !== '') {
            $product = Product::where('sku', $productRef)->first();
        }
        if (! $product && ! empty($productName)) {
            $product = Product::whereRaw('LOWER(name) = ?', [strtolower(trim($productName))])->first();
        }

        return $product;
    }

    /**
     * Find a completed/delivered order that belongs to the customer and contains the product.
     *
     * Logged-in customers are matched by user id or their account email.
     * Guests must supply both the order email and the order number of that order.
     */
    private function findEligibleOrder(?User $user, ?string $email, ?string $orderNumber, Product $product): ?EcommerceOrder
    {
        $email = $email ? strtolower(trim($email)) : null;
        $cleanNum = $orderNumber ? ltrim(trim($orderNumber), '#') : null;

        if (! $user && (empty($email) || empty($cleanNum))) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count(self::REVIEWABLE_ORDER_STATUSES), '?'));

        $query = EcommerceOrder::query()
            ->whereRaw("LOWER(status) IN ({$placeholders})", self::REVIEWABLE_ORDER_STATUSES)
            ->whereHas('items', function ($q) use ($product) {
                $q->where(function ($iq) use ($product) {
                    $iq->where('product_id', $product->id);
                    if (! empty($product->sku)) {
                        $iq->orWhere('product_sku', $product->sku);
                    }
                });
            });

        if ($user) {
            $accountEmail = strtolower(trim((string) $user->email));
            $query->where(function ($q) use ($user, $accountEmail) {
                $q->where('user_id', $user->id);
                if ($accountEmail !== '') {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$accountEmail]);
                }
            });
        } else {
            $query->whereRaw('LOWER(customer_email) = ?', [$email])
                ->where(function ($q) use ($cleanNum) {
                    $q->where('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
                });
        }

        if ($user && ! empty($cleanNum)) {
            $preferred = (clone $query)->where(function ($q) use ($cleanNum) {
                $q->where('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
            })->latest('id')->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return $query->latest('id')->first();
    }

    private function hasAlreadyReviewed(?User $user, ?string $email, Product $product): bool
    {
        $table = (new EcommerceReview)->getTable();
        $hasEmailColumn = Schema::hasColumn($table, 'customer_email');
        $query = EcommerceReview::query()->where('product_id', (string) $product->id);

        if ($user) {
            $accountEmail = strtolower(trim((string) $user->email));
            $query->where(function ($q) use ($user, $accountEmail, $hasEmailColumn) {
                $q->where('user_id', $user->id);
                if ($accountEmail !== '' && $hasEmailColumn) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$accountEmail]);
                }
            });
        } elseif (! empty($email) && $hasEmailColumn) {
            $query->whereRaw('LOWER(customer_email) = ?', [strtolower(trim($email))]);
        } else {
            return false;
        }

        return $query->exists();
    }

    public function canReview(Request $request, $productId)
    {
        $user = $request->user();
        $email = $user ? $user->email : $request->input('email');
        $orderNumber = $request->input('order_number');

        $deny = fn (string $reason, string $message, bool $verified = false, bool $already = false) => response()->json([
            'success' => true,
            'can_review' => false,
            'verified_buyer' => $verified,
            'already_reviewed' => $already,
            'reason' => $reason,
            'message' => $message,
        ]);

        $product = $this->resolveProduct($productId);
        if (! $product) {
            return $deny('product_not_found', 'Product not found.');
        }

        if (! $user && (empty($email) || empty($orderNumber))) {
            return $deny('login_required', 'Please sign in to review products you have purchased.');
        }

        $order = $this->findEligibleOrder($user, $email, $orderNumber, $product);
        if (! $order) {
            return $deny('not_purchased', self::NOT_ELIGIBLE_MESSAGE);
        }

        if ($this->hasAlreadyReviewed($user, $email, $product)) {
            return $deny('already_reviewed', 'You have already submitted a review for this product.', true, true);
        }

        return response()->json([
            'success' => true,
            'can_review' => true,
            'verified_buyer' => true,
            'already_reviewed' => false,
            'order_number' => $order->order_number,
            'reason' => null,
            'message' => 'You can review this product.',
        ]);
    }

    private const NOT_ELIGIBLE_MESSAGE = 'Only customers who purchased this product can leave a review, once their order is marked as completed or delivered.';

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

        $product = $this->resolveProduct($validated['product_id'], $request->input('product_name'));
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }
        $productId = (string) $product->id;

        // Identity comes only from the authenticated session. Guests must prove ownership
        // with the email + order number of a completed/delivered order.
        $email = $user
            ? strtolower(trim((string) $user->email))
            : (! empty($validated['customer_email']) ? strtolower(trim($validated['customer_email'])) : null);
        $orderNumber = $validated['order_number'] ?? null;

        if (! $user && (empty($email) || empty($orderNumber))) {
            return response()->json([
                'success' => false,
                'reason' => 'login_required',
                'message' => 'Please sign in to review products you have purchased.',
            ], 401);
        }

        $order = $this->findEligibleOrder($user, $email, $orderNumber, $product);
        if (! $order) {
            return response()->json([
                'success' => false,
                'reason' => 'not_purchased',
                'message' => self::NOT_ELIGIBLE_MESSAGE,
            ], 403);
        }

        if ($this->hasAlreadyReviewed($user, $email, $product)) {
            return response()->json([
                'success' => false,
                'reason' => 'already_reviewed',
                'message' => 'You have already submitted a review for this product.',
            ], 409);
        }

        if (! $user && ! empty($order->user_id)) {
            $user = User::find($order->user_id);
        }

        if (! empty($validated['customer_name']) && ! in_array($validated['customer_name'], ['Verified Customer', 'Customer'], true)) {
            $customerName = $validated['customer_name'];
        } elseif ($user && ! empty($user->name)) {
            $customerName = $user->name;
        } elseif (! empty($order->customer_name)) {
            $customerName = $order->customer_name;
        } else {
            $customerName = 'Customer';
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
            $reviewData['customer_email'] = $email ?: $order->customer_email;
        }
        if (Schema::hasColumn($table, 'order_number')) {
            $reviewData['order_number'] = $order->order_number;
        }
        if (Schema::hasColumn($table, 'verified_buyer')) {
            $reviewData['verified_buyer'] = true;
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
