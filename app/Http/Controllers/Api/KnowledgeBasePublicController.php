<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\KnowledgeBaseBannerController;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseBanner;
use App\Models\KnowledgeBaseCategory;
use App\Models\KnowledgeBaseSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, read-only Knowledge Base API used by the website.
 * Only published articles in active categories are ever returned.
 */
class KnowledgeBasePublicController extends Controller
{
    private const HUB_POPULAR = 4;
    private const HUB_LATEST = 10;
    private const RELATED = 4;

    /** GET /api/knowledge-base/banners/{pageKey} */
    public function banner(string $pageKey): JsonResponse
    {
        if (!in_array($pageKey, KnowledgeBaseBanner::PAGE_KEYS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown banner page'], 404);
        }

        $defaults = KnowledgeBaseBannerController::defaults()[$pageKey];
        $banner = KnowledgeBaseBanner::where('page_key', $pageKey)->first();

        if ($banner && !$banner->is_active) {
            return response()->json(['success' => true, 'data' => ['isActive' => false]]);
        }

        $imagePath = $banner?->image_path;
        return response()->json([
            'success' => true,
            'data' => [
                'isActive' => true,
                'eyebrow' => $banner?->eyebrow ?? $defaults['eyebrow'],
                'title' => $banner?->title ?? $defaults['title'],
                'highlight' => $banner ? $banner->highlight : $defaults['highlight'],
                'description' => $banner?->description ?? $defaults['description'],
                'searchPlaceholder' => $banner?->search_placeholder ?? $defaults['search_placeholder'],
                'features' => $banner ? ($banner->features ?? []) : $defaults['features'],
                'imageUrl' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
            ],
        ]);
    }

    /**
     * GET /api/knowledge-base/hub
     * Featured article, popular articles, latest resources and category tiles for the hub page.
     */
    public function hub(): JsonResponse
    {
        $published = $this->publishedQuery();

        $featured = (clone $published)->orderByDesc('views')->orderByDesc('id')->first();

        $popular = (clone $published)
            ->when($featured, fn ($q) => $q->where('id', '!=', $featured->id))
            ->orderByDesc('views')
            ->orderByDesc('id')
            ->limit(self::HUB_POPULAR)
            ->get();

        $latest = (clone $published)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::HUB_LATEST)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $this->categoryTiles(),
                'featured' => $featured ? $this->formatCard($featured->load(['category', 'subcategory'])) : null,
                'popular' => $popular->map(fn ($a) => $this->formatCard($a->load(['category', 'subcategory'])))->values(),
                'latest' => $latest->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'slug' => $a->slug])->values(),
            ],
        ]);
    }

    /**
     * GET /api/knowledge-base/navigation
     * Category tree (with article counts) for the sidebar.
     */
    public function navigation(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->categoryTree()]);
    }

    /**
     * GET /api/knowledge-base/articles
     * Query: category (slug), subcategory (slug), q, page, per_page
     */
    public function articles(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 12), 1), 48);

        $query = $this->publishedQuery()->with(['category', 'subcategory']);

        if ($request->filled('category')) {
            $slug = (string) $request->input('category');
            $query->whereHas('category', fn ($q) => $q->where('slug', $slug));
        }

        if ($request->filled('subcategory')) {
            $slug = (string) $request->input('subcategory');
            $query->whereHas('subcategory', fn ($q) => $q->where('slug', $slug));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($w) use ($q) {
                $w->where('title', 'ilike', "%{$q}%")
                    ->orWhere('summary', 'ilike', "%{$q}%");
            });
        }

        $paginator = $query->orderByDesc('published_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn ($a) => $this->formatCard($a))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/knowledge-base/articles/{slug}
     * Full article. Each successful read counts as one view.
     */
    public function show(string $slug): JsonResponse
    {
        $article = $this->publishedQuery()
            ->where('slug', $slug)
            ->with(['category', 'subcategory', 'relatedArticles' => fn ($q) => $q->where('status', 'published')])
            ->first();

        if (!$article) {
            return response()->json(['success' => false, 'message' => 'Article not found'], 404);
        }

        $article->increment('views');
        $article->refresh();

        $related = $article->relatedArticles;
        if ($related->isEmpty() && $article->category_id) {
            $related = $this->publishedQuery()
                ->where('category_id', $article->category_id)
                ->where('id', '!=', $article->id)
                ->orderByDesc('views')
                ->limit(self::RELATED)
                ->with(['category', 'subcategory'])
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->formatCard($article), [
                'contentBlocks' => $article->content_blocks ?? [],
                'attachments' => $article->attachments ?? [],
                'metaTitle' => $article->meta_title,
                'metaDescription' => $article->meta_description,
                'relatedArticles' => $related->take(self::RELATED)->map(fn ($r) => $this->formatCard($r))->values(),
            ]),
        ]);
    }

    /* ----------------------------------------------------------------------- */

    private function publishedQuery()
    {
        return KnowledgeBaseArticle::query()
            ->where('status', 'published')
            ->whereHas('category', fn ($q) => $q->where('status', 'active'));
    }

    private function categoryTree()
    {
        return KnowledgeBaseCategory::query()
            ->where('status', 'active')
            ->withCount(['articles as article_count' => fn ($q) => $q->where('status', 'published')])
            ->with(['subcategories' => fn ($q) => $q
                ->where('status', 'active')
                ->withCount(['articles as article_count' => fn ($a) => $a->where('status', 'published')])
                ->orderBy('display_order')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (KnowledgeBaseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'metaDescription' => $c->meta_description,
                'iconUrl' => $this->url($c->icon_path),
                'coverUrl' => $this->url($c->cover_image_path),
                'articleCount' => (int) $c->article_count,
                'subcategories' =>$c->subcategories->map(fn (KnowledgeBaseSubcategory $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'articleCount' => (int) $s->article_count,
                ])->values(),
            ])
            ->values();
    }

    private function categoryTiles()
    {
        return KnowledgeBaseCategory::query()
            ->where('status', 'active')
            ->withCount(['articles as article_count' => fn ($q) => $q->where('status', 'published')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (KnowledgeBaseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'iconUrl' => $this->url($c->icon_path),
                'coverUrl' => $this->url($c->cover_image_path),
                'articleCount' => (int) $c->article_count,
            ])
            ->values();
    }

    private function formatCard(KnowledgeBaseArticle $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'slug' => $a->slug,
            'summary' => $a->summary ?? '',
            'coverUrl' => $this->url($a->cover_image_path),
            'category' => $a->category ? ['name' => $a->category->name, 'slug' => $a->category->slug] : null,
            'subcategory' => $a->subcategory ? ['name' => $a->subcategory->name, 'slug' => $a->subcategory->slug] : null,
            'views' => (int) $a->views,
            'publishedAt' => $a->published_at?->toIso8601String(),
            'publishedLabel' => ($a->published_at ?? $a->updated_at)?->format('M j, Y'),
        ];
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
