<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceReview;
use App\Models\EcommerceOrder;
use App\Models\User;
use App\Models\Product;
use App\Services\Moderation\ContentFilter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class EcommerceReviewController extends Controller
{
    private const MAX_PHOTOS = 5;

    private function isAdmin(?User $user): bool
    {
        return $user && method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'admin', 'editor']);
    }

    /**
     * Review photos: uploaded image files (`photos[]` / `images[]`) are stored on the public
     * disk; previously uploaded paths may be passed back as strings, but only paths on this
     * site's storage are kept, so reviews cannot point at outside images.
     *
     * @return array{0: ?array, 1: ?string}  [images or null when none were sent, error message]
     */
    private function collectPhotos(Request $request): array
    {
        $files = [];
        foreach (['photos', 'images'] as $key) {
            $value = $request->file($key);
            if ($value) {
                $files = array_merge($files, is_array($value) ? $value : [$value]);
            }
        }

        $strings = [];
        foreach (['images', 'photos'] as $key) {
            $value = $request->input($key);
            if (is_array($value)) {
                $strings = array_merge($strings, array_filter($value, 'is_string'));
            }
        }

        if (empty($files) && empty($strings) && ! $request->has('images') && ! $request->has('photos')) {
            return [null, null];
        }

        if (count($files) + count($strings) > self::MAX_PHOTOS) {
            return [null, 'You can add up to ' . self::MAX_PHOTOS . ' photos.'];
        }

        $kept = [];
        $storageBase = rtrim(asset('storage'), '/');
        foreach ($strings as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            // Review modals send photos picked in the browser as base64 data URLs.
            if (str_starts_with($path, 'data:')) {
                if (! preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,(.+)$#is', $path, $m)) {
                    return [null, 'Photos must be JPG, PNG, WEBP or GIF images.'];
                }
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || @getimagesizefromstring($bytes) === false) {
                    return [null, 'A photo could not be read. Please choose another image.'];
                }
                if (strlen($bytes) > 5 * 1024 * 1024) {
                    return [null, 'Each photo must be 5 MB or smaller.'];
                }
                $ext = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
                $name = 'reviews/' . \Illuminate\Support\Str::random(40) . '.' . $ext;
                \Illuminate\Support\Facades\Storage::disk('public')->put($name, $bytes);
                $kept[] = '/storage/' . $name;
                continue;
            }
            if (str_starts_with($path, $storageBase . '/')) {
                $path = '/storage/' . substr($path, strlen($storageBase) + 1);
            }
            if (! preg_match('#^/?storage/reviews/[A-Za-z0-9._\-/]+\.(jpe?g|png|webp|gif)$#i', $path) || str_contains($path, '..')) {
                return [null, 'Photos must be uploaded as image files.'];
            }
            $kept[] = '/' . ltrim($path, '/');
        }

        foreach ($files as $file) {
            if (! $file->isValid()) {
                return [null, 'A photo could not be uploaded. Please try again.'];
            }
            $mime = strtolower((string) $file->getMimeType());
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                return [null, 'Photos must be JPG, PNG, WEBP or GIF images.'];
            }
            if ($file->getSize() > 5 * 1024 * 1024) {
                return [null, 'Each photo must be 5 MB or smaller.'];
            }
            $kept[] = '/storage/' . $file->store('reviews', 'public');
        }

        return [array_values(array_unique($kept)), null];
    }

    public function index(Request $request)
    {
        if ($request->filled('product_id')) {
            // Public product reviews: no reviewer account or order details.
            $query = EcommerceReview::with(['product.category'])
                ->where('product_id', (string) $request->input('product_id'));

            // Unapproved and hidden reviews are only for admins.
            if (! ($request->boolean('include_pending') && $this->isAdmin($request->user()))) {
                $query->whereRaw("LOWER(status) IN ('approved', 'active')");
            }

            return response()->json([
                'success' => true,
                'data' => $query->latest('id')->get(),
            ]);
        }

        // Without a product filter this lists one customer's own reviews (pending included),
        // so identity comes only from the signed-in session, never from request parameters.
        $query = EcommerceReview::with(['product.category', 'user', 'order']);
        $user = $request->user();
        if (! $user) {
            $query->whereRaw('1 = 0');
        } else {
            $hasEmailColumn = Schema::hasColumn((new EcommerceReview)->getTable(), 'customer_email');
            $accountEmail = strtolower(trim((string) $user->email));
            $query->where(function ($q) use ($user, $accountEmail, $hasEmailColumn) {
                $q->where('user_id', $user->id);
                if ($accountEmail !== '' && $hasEmailColumn) {
                    $q->orWhere(function ($eq) use ($accountEmail) {
                        $eq->whereNull('user_id')->whereRaw('LOWER(customer_email) = ?', [$accountEmail]);
                    });
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
                'data' => collect($paginated->items())->each->makeVisible(EcommerceReview::PRIVATE_FIELDS)->values(),
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
            'data' => $query->latest('id')->get()->each->makeVisible(EcommerceReview::PRIVATE_FIELDS),
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

        // Reviews are always tied to a customer account.
        if (! $user) {
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
            'comment' => ['nullable', 'string', 'max:5000'],
            'order_number' => ['nullable', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ]);

        if ($problem = ContentFilter::check([
            'name' => $validated['customer_name'] ?? null,
            'title' => $validated['title'] ?? null,
            'review' => $validated['comment'] ?? null,
        ])) {
            return response()->json(['success' => false, 'reason' => 'content_rejected', 'message' => $problem], 422);
        }

        $product = $this->resolveProduct($validated['product_id'], $request->input('product_name'));
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }
        $productId = (string) $product->id;

        // Reviews are always linked to the signed-in customer's account; identity comes only
        // from the session.
        $email = $user
            ? strtolower(trim((string) $user->email))
            : (! empty($validated['customer_email']) ? strtolower(trim($validated['customer_email'])) : null);
        $orderNumber = $validated['order_number'] ?? null;

        if (! $user) {
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

        if (! empty($validated['customer_name']) && ! in_array($validated['customer_name'], ['Verified Customer', 'Customer'], true)) {
            $customerName = $validated['customer_name'];
        } elseif ($user && ! empty($user->name)) {
            $customerName = $user->name;
        } elseif (! empty($order->customer_name)) {
            $customerName = $order->customer_name;
        } else {
            $customerName = 'Customer';
        }

        [$images, $photoError] = $this->collectPhotos($request);
        if ($photoError) {
            return response()->json(['success' => false, 'reason' => 'invalid_photos', 'message' => $photoError], 422);
        }

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
            $reviewData['images'] = $images;
        }

        // Always save as STATUS_PENDING so admin must review and approve/decline.
        // The unique index catches a second review racing past the check above.
        try {
            $review = EcommerceReview::create($reviewData);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                return response()->json([
                    'success' => false,
                    'reason' => 'already_reviewed',
                    'message' => 'You have already submitted a review for this product.',
                ], 409);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully and is pending admin approval.',
            'data' => $review->load(['product'])->makeVisible(EcommerceReview::PRIVATE_FIELDS),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $item = EcommerceReview::with('product', 'user')->findOrFail($id);
        $isPublic = in_array(strtolower((string) $item->status), ['approved', 'active'], true);
        $privileged = $this->ownsReview($request->user(), $item) || $this->isAdmin($request->user());
        if (! $isPublic && ! $privileged) {
            abort(404);
        }
        if ($privileged) {
            $item->makeVisible(EcommerceReview::PRIVATE_FIELDS);
        } else {
            $item->unsetRelation('user');
        }
        return response()->json(['success' => true, 'data' => $item]);
    }

    /**
     * A review belongs to the signed-in customer when it carries their user id, or (for
     * reviews left before they had an account link) their account email.
     */
    private function ownsReview(?User $user, EcommerceReview $review): bool
    {
        if (! $user) {
            return false;
        }
        if (! empty($review->user_id)) {
            return (int) $review->user_id === (int) $user->id;
        }
        $accountEmail = strtolower(trim((string) $user->email));
        $reviewEmail = strtolower(trim((string) ($review->customer_email ?? '')));

        return $accountEmail !== '' && $accountEmail === $reviewEmail;
    }

    private function notOwner()
    {
        return response()->json([
            'success' => false,
            'message' => 'You can only change your own reviews.',
        ], 403);
    }

    public function update(Request $request, $id)
    {
        $item = EcommerceReview::findOrFail($id);
        if (! $this->ownsReview($request->user(), $item)) {
            return $this->notOwner();
        }

        $validated = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'images' => ['sometimes', 'nullable', 'array'],
            'photos' => ['sometimes', 'nullable', 'array'],
        ]);
        unset($validated['images'], $validated['photos']);

        if ($problem = ContentFilter::check([
            'title' => $validated['title'] ?? null,
            'review' => $validated['comment'] ?? null,
        ])) {
            return response()->json(['success' => false, 'reason' => 'content_rejected', 'message' => $problem], 422);
        }

        [$images, $photoError] = $this->collectPhotos($request);
        if ($photoError) {
            return response()->json(['success' => false, 'reason' => 'invalid_photos', 'message' => $photoError], 422);
        }
        if (is_array($images)) {
            $validated['images'] = $images;
        }

        if (empty($validated)) {
            return response()->json(['success' => true, 'data' => $item->load(['product', 'user'])->makeVisible(EcommerceReview::PRIVATE_FIELDS)]);
        }

        // Edited text has not been moderated yet, so it goes back to the approval queue.
        $validated['status'] = EcommerceReview::STATUS_PENDING;
        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Review updated and sent for approval again.',
            'data' => $item->load(['product', 'user'])->makeVisible(EcommerceReview::PRIVATE_FIELDS),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $item = EcommerceReview::findOrFail($id);
        if (! $this->ownsReview($request->user(), $item)) {
            return $this->notOwner();
        }
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }
}
