<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceCompareItem;
use App\Models\EcommerceReview;
use App\Models\Product;
use Illuminate\Http\Request;

class CompareProductController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $withRelations = [
            'product.category.parent',
            'product.previewAssets' => fn ($query) => $query->where('is_active', true),
            'product.reviews' => fn ($query) => $query->whereRaw('LOWER(status) = ?', ['approved']),
        ];
        if (\Illuminate\Support\Facades\Schema::hasTable('product_customization_options')) {
            $withRelations['product.customizationOptions'] = fn ($query) => $query->where('is_active', true);
        }

        $items = EcommerceCompareItem::query()
            ->where('user_id', $user->id)
            ->with($withRelations)
            ->latest()
            ->get();

        $products = $items->pluck('product')->filter()->values();
        $this->attachReviewStats($products);

        return response()->json([
            'success' => true,
            'data' => $products->map(fn (Product $product) => $this->productPayload($product))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
        ]);

        $product = Product::find($validated['product_id']);
        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'The selected product id is invalid.',
            ], 404);
        }

        $count = EcommerceCompareItem::query()
            ->where('user_id', $user->id)
            ->count();

        $existing = EcommerceCompareItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if (! $existing && $count >= 4) {
            $oldest = EcommerceCompareItem::query()
                ->where('user_id', $user->id)
                ->oldest('id')
                ->first();
            if ($oldest) {
                $oldest->delete();
            }
        }

        EcommerceCompareItem::query()->firstOrCreate([
            'user_id' => $user->id,
            'product_id' => $validated['product_id'],
        ]);

        return $this->index($request);
    }

    public function destroy(Request $request, $productId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        EcommerceCompareItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->delete();

        return $this->index($request);
    }

    public function clear(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'Compared products cleared successfully.',
                'data' => [],
            ]);
        }

        EcommerceCompareItem::query()
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Compared products cleared successfully.',
            'data' => [],
        ]);
    }

    private function attachReviewStats($products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $missing = $products->filter(fn (Product $p) => ! $p->relationLoaded('reviews'));
        if ($missing->isNotEmpty()) {
            $missing->load([
                'reviews' => fn ($query) => $query->whereRaw('LOWER(status) = ?', ['approved']),
            ]);
        }

        $products->each(function (Product $product) {
            $reviews = $product->reviews ?? collect();
            $reviewValues = $reviews->pluck('rating')->map(fn ($rating) => (int) $rating)->filter();
            $product->setAttribute('review_stats', [
                'average_rating' => $reviewValues->isNotEmpty() ? round($reviewValues->avg(), 1) : 0,
                'review_count' => $reviews->count(),
            ]);
        });
    }

    private function productPayload(Product $product): array
    {
        $attributes = $product->attributes;
        if (is_string($attributes)) {
            $decoded = json_decode($attributes, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $attributes = $decoded;
            }
        }
        $attrs = is_array($attributes) ? $attributes : [];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description ?: $product->short_description,
            'short_description' => $product->short_description,
            'price' => $product->price,
            'sale_price' => $product->sale_price,
            'cost_price' => $product->cost_price,
            'product_type' => $product->product_type,
            'is_digital' => (bool) $product->is_digital,
            'stock_quantity' => $product->stock_quantity,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'attributes' => $attrs,
            'available_colors' => $attrs['availableColors'] ?? ($attrs['available_colors'] ?? null),
            'available_sizes' => $attrs['availableSizes'] ?? ($attrs['available_sizes'] ?? null),
            'variants' => $product->variants,
            'tags' => $product->tags,
            'images' => $product->images ?? [],
            'preview_assets' => $product->previewAssets?->map(fn ($asset) => [
                'id' => $asset->id,
                'image_path' => $asset->image_path,
                'side' => $asset->side,
            ])->values() ?? [],
            'review_stats' => $product->review_stats,
            'colors' => $product->colors ?? null,
            'customization_options' => $product->relationLoaded('customizationOptions')
                ? $product->customizationOptions->map(fn ($opt) => [
                    'id' => $opt->id,
                    'option_type' => $opt->option_type,
                    'option_key' => $opt->option_key,
                    'label' => $opt->label,
                    'price_modifier' => $opt->price_modifier,
                ])->values()
                : [],
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
        ];
    }
}
