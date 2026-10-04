<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\EcommerceReview;
use App\Models\EcommerceCoupon;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $withRelations = [];
            if (\Illuminate\Support\Facades\Schema::hasTable('categories')) {
                $withRelations[] = 'category';
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('product_preview_assets')) {
                $withRelations['previewAssets'] = fn ($assetQuery) => $assetQuery->where('is_active', true);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_reviews')) {
                $withRelations['reviews'] = fn ($reviewQuery) => $reviewQuery->whereRaw('LOWER(status) = ?', ['approved']);
            }

            $query = Product::with($withRelations)->where('is_active', true);

            if ($request->filled('product_type')) {
                $query->where('product_type', $request->product_type);
            }

            if ($request->filled('category_id')) {
                $categoryId = $request->category_id;
                try {
                    $category = Category::with('children')->find($categoryId);
                    if ($category && $category->children && $category->children->isNotEmpty()) {
                        $categoryIds = $category->children->pluck('id')->prepend($category->id);
                        $query->whereIn('category_id', $categoryIds);
                    } else {
                        $query->where('category_id', $categoryId);
                    }
                } catch (\Throwable) {
                    $query->where('category_id', $categoryId);
                }
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                      ->orWhere('sku', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
                });
            }

            if ($request->filled('min_price')) {
                $minPrice = (float) $request->min_price;
                $query->where(function ($q) use ($minPrice) {
                    $q->where('sale_price', '>=', $minPrice)
                      ->orWhere(function ($sub) use ($minPrice) {
                          $sub->whereNull('sale_price')->where('price', '>=', $minPrice);
                      });
                });
            }

            if ($request->filled('max_price')) {
                $maxPrice = (float) $request->max_price;
                $query->where(function ($q) use ($maxPrice) {
                    $q->where(function ($sub) use ($maxPrice) {
                        $sub->whereNotNull('sale_price')->where('sale_price', '<=', $maxPrice);
                    })->orWhere(function ($sub) use ($maxPrice) {
                        $sub->whereNull('sale_price')->where('price', '<=', $maxPrice);
                    });
                });
            }

            if ($request->filled('sort')) {
                switch ($request->sort) {
                    case 'price_asc':
                        $query->orderByRaw('COALESCE(sale_price, price) asc');
                        break;
                    case 'price_desc':
                        $query->orderByRaw('COALESCE(sale_price, price) desc');
                        break;
                    case 'name_asc':
                        $query->orderBy('name', 'asc');
                        break;
                    case 'name_desc':
                        $query->orderBy('name', 'desc');
                        break;
                    case 'newest':
                    case 'relevance':
                        $query->orderBy('created_at', 'desc');
                        break;
                    case 'oldest':
                        $query->orderBy('created_at', 'asc');
                        break;
                    default:
                        $query->orderBy('created_at', 'desc');
                }
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $products = $query->paginate((int) $request->get('per_page', 12));
            $this->attachReviewStats($products->getCollection());
            $this->attachFrontendAliases($products->getCollection());
            $this->attachQuestionStats($products->getCollection());

            return response()->json($products);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController@index error: ' . $e->getMessage());

            try {
                $fallback = Product::where('is_active', true)->paginate((int) $request->get('per_page', 12));
                return response()->json($fallback);
            } catch (\Throwable $e2) {
                return response()->json([
                    'current_page' => 1,
                    'data' => [],
                    'total' => 0,
                    'per_page' => 12,
                    'last_page' => 1,
                ]);
            }
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        try {
            if (!$product->is_active) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            $loads = [];
            if (\Illuminate\Support\Facades\Schema::hasTable('categories')) {
                $loads[] = 'category.parent';
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('product_preview_assets')) {
                $loads['previewAssets'] = fn ($query) => $query->where('is_active', true);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_coupons') && \Illuminate\Support\Facades\Schema::hasTable('ecommerce_coupon_product')) {
                $loads['coupons'] = fn ($query) => $query
                    ->where('is_active', true)
                    ->where(function ($inner) {
                        $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($inner) {
                        $inner->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                    })
                    ->where(function ($inner) {
                        $inner->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit');
                    });
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('product_customization_options')) {
                $loads['customizationOptions'] = fn ($query) => $query->where('is_active', true);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('product_pricing_rules')) {
                $loads['pricingRules'] = fn ($query) => $query->where('is_active', true);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_reviews')) {
                $loads['reviews'] = fn ($query) => $query->whereRaw('LOWER(status) = ?', ['approved'])->latest();
            }

            if (!empty($loads)) {
                try {
                    $product->load($loads);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ProductController@show load relations warning: ' . $e->getMessage());
                }
            }

            $relatedProducts = $this->relationProducts($product, 'related', 8, true);
            $recentWorkProducts = $this->relationProducts($product, 'recent_work', 5, true);
            $featuredProducts = $this->relationProducts($product, 'featured', 8, true);
            $this->attachReviewStats($relatedProducts);
            $this->attachReviewStats($recentWorkProducts);
            $this->attachReviewStats($featuredProducts);

            $this->attachReviewStats(collect([$product]));
            $this->attachFrontendAliases(collect([$product]));
            $this->attachQuestionStats(collect([$product]));
            $this->attachPublicCoupons(collect([$product]));

            $custOptions = $product->customizationOptions ?? collect();
            $product->setAttribute('customization_options_grouped', $custOptions ? $custOptions->groupBy('option_type')->values() : []);
            $product->setAttribute('related_products', $relatedProducts);
            $product->setAttribute('recent_work_products', $recentWorkProducts);
            $product->setAttribute('featured_products', $featuredProducts);

            return response()->json($product);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController@show error: ' . $e->getMessage());
            return response()->json($product);
        }
    }

    private function relationProducts(Product $product, string $type, int $limit, bool $fallback = false)
    {
        try {
            $eagerLoads = [];
            if (\Illuminate\Support\Facades\Schema::hasTable('categories')) {
                $eagerLoads[] = 'category.parent';
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('product_preview_assets')) {
                $eagerLoads['previewAssets'] = fn ($assetQuery) => $assetQuery->where('is_active', true);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_reviews')) {
                $eagerLoads['reviews'] = fn ($reviewQuery) => $reviewQuery->whereRaw('LOWER(status) = ?', ['approved']);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('product_related_products')) {
                $products = $product->relatedProducts()
                    ->with($eagerLoads)
                    ->where('is_active', true)
                    ->wherePivot('relation_type', $type)
                    ->orderBy('product_related_products.sort_order')
                    ->limit($limit)
                    ->get();

                if ($products->isNotEmpty() || ! $fallback) {
                    $this->attachFrontendAliases($products);
                    return $products;
                }
            }

            $fallbackProducts = Product::query()
                ->with($eagerLoads)
                ->where('is_active', true)
                ->where('id', '!=', $product->id)
                ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
                ->latest()
                ->limit($limit)
                ->get();

            $this->attachFrontendAliases($fallbackProducts);
            return $fallbackProducts;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ProductController@relationProducts warning: ' . $e->getMessage());
            return collect();
        }
    }

    private function attachReviewStats($products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('ecommerce_reviews')) {
            $products->each(function (Product $product) {
                $product->setAttribute('review_stats', [
                    'average_rating' => null,
                    'review_count' => 0,
                    'rating_distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
                ]);
            });
            return;
        }

        $missing = $products->filter(fn (Product $p) => ! $p->relationLoaded('reviews'));
        if ($missing->isNotEmpty()) {
            $missing->load([
                'reviews' => fn ($reviewQuery) => $reviewQuery->whereRaw('LOWER(status) = ?', ['approved']),
            ]);
        }

        $products->each(function (Product $product) {
            $reviews = $product->reviews ?? collect();

            $reviewValues = $reviews->pluck('rating')->map(fn ($rating) => (int) $rating)->filter();
            $averageRating = $reviewValues->isNotEmpty() ? round($reviewValues->avg(), 1) : null;
            $distribution = collect([5, 4, 3, 2, 1])->mapWithKeys(function ($score) use ($reviewValues) {
                return [$score => $reviewValues->filter(fn ($rating) => $rating === $score)->count()];
            });

            $product->setAttribute('review_stats', [
                'average_rating' => $averageRating,
                'review_count' => $reviews->count(),
                'rating_distribution' => $distribution,
            ]);
        });
    }

    private function attachFrontendAliases($products): void
    {
        $products->each(function (Product $product) {
            if ($product->relationLoaded('previewAssets')) {
                $product->setAttribute('previewAssets', $product->previewAssets);
            }

            if ($product->relationLoaded('customizationOptions')) {
                $product->setAttribute('customizationOptions', $product->customizationOptions);
            }
        });
    }

    private function attachPublicCoupons($products): void
    {
        $products->each(function (Product $product) {
            if ($product->relationLoaded('coupons')) {
                $product->setRelation('coupons', $product->coupons->map(fn (EcommerceCoupon $coupon) => $coupon->toPublicArray())->values());
            }
        });
    }

    private function attachQuestionStats($products): void
    {
        $ids = $products->pluck('id')->filter()->values();
        $defaults = [
            'questions_count' => 0,
            'unanswered_questions_count' => 0,
            'answers_count' => 0,
            'latest_question' => null,
            'latest_question_at' => null,
        ];

        if ($ids->isEmpty()) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('ecommerce_product_questions')) {
            $products->each(fn (Product $p) => collect($defaults)->each(fn ($v, $k) => $p->setAttribute($k, $v)));
            return;
        }

        $questions = \App\Models\EcommerceProductQuestion::query()
            ->whereIn('product_id', $ids)
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('product_id');

        $products->each(function (Product $product) use ($questions, $defaults) {
            $list = $questions->get($product->id, collect());
            $latest = $list->first();
            $stats = [
                'questions_count' => $list->count(),
                'unanswered_questions_count' => $list->where('status', 'unanswered')->count(),
                'answers_count' => (int) $list->sum('replies_count'),
                'latest_question' => $latest?->question,
                'latest_question_at' => $latest?->created_at?->toIso8601String(),
            ];
            foreach (array_merge($defaults, $stats) as $key => $value) {
                $product->setAttribute($key, $value);
            }
        });
    }
}