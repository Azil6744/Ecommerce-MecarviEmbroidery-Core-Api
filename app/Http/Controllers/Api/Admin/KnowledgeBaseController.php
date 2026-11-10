<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\KnowledgeBaseSubcategory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KnowledgeBaseController extends Controller
{
    /** Statuses an article can have. */
    public const STATUSES = ['published', 'draft', 'archived'];

    /** Content block types the article editor can produce. */
    public const BLOCK_TYPES = [
        'paragraph', 'heading', 'step', 'image', 'video',
        'bullet_list', 'numbered_list', 'table', 'quote', 'divider',
    ];

    /** A published article not updated for this many days needs review. */
    public const OVERDUE_DAYS = 180;

    private const TOP_VIEWED_LIMIT = 5;
    private const MAX_RELATED = 10;

    /**
     * GET /api/admin/knowledge-base/articles
     * Query: search, category_id, subcategory_id, status, attention (draft|uncategorized|overdue), per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

        $query = KnowledgeBaseArticle::query()
            ->with(['category', 'subcategory', 'relatedArticles'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('summary', 'ilike', "%{$search}%")
                    ->orWhere('tag', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', (int) $request->input('subcategory_id'));
        }

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        switch ($request->input('attention')) {
            case 'draft':
                $query->where('status', 'draft');
                break;
            case 'uncategorized':
                $query->whereNull('category_id');
                break;
            case 'overdue':
                $query->where('status', 'published')
                    ->where('updated_at', '<', now()->subDays(self::OVERDUE_DAYS));
                break;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (KnowledgeBaseArticle $a) => $this->formatArticle($a))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/knowledge-base/articles
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateArticle($request);
        $this->assertSubcategoryBelongsToCategory($validated);

        $attributes = $this->articleAttributes($validated);
        $attributes['slug'] = $this->uniqueSlug($validated['slug'] ?? $validated['title']);
        $attributes['views'] = 0;
        $attributes['helpful_count'] = 0;
        $attributes['icon'] = 'doc';
        $attributes['tone'] = 'purple';

        $article = KnowledgeBaseArticle::create($attributes);
        $this->syncRelated($article, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Article saved successfully',
            'data' => $this->formatArticle($article->fresh()->load(['category', 'subcategory', 'relatedArticles'])),
        ], 201);
    }

    /**
     * GET /api/admin/knowledge-base/articles/{id}
     */
    public function show($id): JsonResponse
    {
        $article = KnowledgeBaseArticle::with(['category', 'subcategory', 'relatedArticles'])->find($id);
        if (!$article) {
            return response()->json(['success' => false, 'message' => 'Article not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatArticle($article),
        ]);
    }

    /**
     * PUT /api/admin/knowledge-base/articles/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $article = KnowledgeBaseArticle::find($id);
        if (!$article) {
            return response()->json(['success' => false, 'message' => 'Article not found'], 404);
        }

        $validated = $this->validateArticle($request, true);
        $this->assertSubcategoryBelongsToCategory($validated, $article);

        $attributes = $this->articleAttributes($validated, $article);
        if (array_key_exists('slug', $validated) || array_key_exists('title', $validated)) {
            $attributes['slug'] = $this->uniqueSlug(
                $validated['slug'] ?? $validated['title'] ?? $article->title,
                $article->id
            );
        }
        $article->update($attributes);
        if (array_key_exists('related_article_ids', $validated)) {
            $this->syncRelated($article, $validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Article updated successfully',
            'data' => $this->formatArticle($article->fresh()->load(['category', 'subcategory', 'relatedArticles'])),
        ]);
    }

    /**
     * DELETE /api/admin/knowledge-base/articles/{id}
     */
    public function destroy($id): JsonResponse
    {
        $article = KnowledgeBaseArticle::find($id);
        if (!$article) {
            return response()->json(['success' => false, 'message' => 'Article not found'], 404);
        }

        $article->delete();

        return response()->json([
            'success' => true,
            'message' => 'Article deleted successfully',
        ]);
    }

    /**
     * POST /api/admin/knowledge-base/uploads
     * Stores a file the article editor references (attachments, content images, category/subcategory icons).
     * Form fields: file, kind (attachment|image|icon)
     */
    public function upload(Request $request): JsonResponse
    {
        $kind = $request->input('kind', 'attachment');

        $rules = match ($kind) {
            'icon' => ['file' => ['required', 'file', 'mimes:png,jpg,jpeg,svg', 'max:2048']],
            'image' => ['file' => ['required', 'file', 'mimes:png,jpg,jpeg,svg', 'max:10240']],
            default => ['file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,svg', 'max:10240']],
        };
        $request->validate(array_merge($rules, [
            'kind' => ['nullable', Rule::in(['attachment', 'image', 'icon'])],
        ]));

        $file = $request->file('file');
        $folder = 'knowledge-base/' . ($kind === 'attachment' ? 'attachments' : ($kind === 'icon' ? 'icons' : 'images'));
        $path = $file->store($folder, 'public');

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $file->getClientOriginalName(),
                'size' => $this->formatBytes($file->getSize()),
                'type' => $this->fileType($file->getClientOriginalExtension()),
                'url' => Storage::disk('public')->url($path),
                'path' => $path,
            ],
        ], 201);
    }

    /**
     * GET /api/admin/knowledge-base/stats
     */
    public function stats(): JsonResponse
    {
        $overdueCutoff = now()->subDays(self::OVERDUE_DAYS);

        $drafts = KnowledgeBaseArticle::where('status', 'draft')->count();
        $uncategorized = KnowledgeBaseArticle::whereNull('category_id')->count();
        $overdue = KnowledgeBaseArticle::where('status', 'published')
            ->where('updated_at', '<', $overdueCutoff)
            ->count();

        $topViewed = KnowledgeBaseArticle::with(['category', 'subcategory', 'relatedArticles'])
            ->orderByDesc('views')
            ->orderByDesc('id')
            ->limit(self::TOP_VIEWED_LIMIT)
            ->get()
            ->map(fn (KnowledgeBaseArticle $a) => $this->formatArticle($a))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'totalArticles' => KnowledgeBaseArticle::count(),
                'categories' => KnowledgeBaseCategory::count(),
                'totalViews' => (int) KnowledgeBaseArticle::sum('views'),
                'helpfulVotes' => (int) KnowledgeBaseArticle::sum('helpful_count'),
                'drafts' => $drafts,
                'uncategorized' => $uncategorized,
                'overdue' => $overdue,
                'attentionTotal' => $drafts + $uncategorized + $overdue,
                'topViewed' => $topViewed,
            ],
        ]);
    }

    /* ----------------------------------------------------------------------- */

    private function validateArticle(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:120'],
            'category_id' => [$required, 'integer', Rule::exists('knowledge_base_categories', 'id')],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('knowledge_base_subcategories', 'id')],
            'summary' => [$required, 'string', 'max:160'],
            'content_blocks' => [$required, 'array', 'max:200'],
            'content_blocks.*' => ['array'],
            'content_blocks.*.id' => ['nullable', 'string', 'max:64'],
            'content_blocks.*.type' => ['required', Rule::in(self::BLOCK_TYPES)],
            'content_blocks.*.title' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.text' => ['nullable', 'string', 'max:5000'],
            'content_blocks.*.caption' => ['nullable', 'string', 'max:500'],
            'content_blocks.*.url' => ['nullable', 'string', 'max:2048'],
            'content_blocks.*.level' => ['nullable', 'integer', 'between:1,3'],
            'content_blocks.*.items' => ['nullable', 'array', 'max:100'],
            'content_blocks.*.items.*' => ['nullable', 'string', 'max:1000'],
            'content_blocks.*.rows' => ['nullable', 'array', 'max:100'],
            'content_blocks.*.rows.*' => ['nullable', 'array', 'max:50'],
            'content_blocks.*.rows.*.*' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'publish_date' => ['nullable', 'date'],
            'cover_image_path' => ['nullable', 'string', 'max:500'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.url' => ['required_with:attachments', 'string', 'max:2048'],
            'attachments.*.size' => ['nullable', 'string', 'max:50'],
            'attachments.*.type' => ['nullable', 'string', 'max:20'],
            'related_article_ids' => ['nullable', 'array', 'max:' . self::MAX_RELATED],
            'related_article_ids.*' => ['integer', 'distinct', Rule::exists('knowledge_base_articles', 'id')],
        ]);
    }

    /** A subcategory must belong to the chosen category. */
    private function assertSubcategoryBelongsToCategory(array $validated, ?KnowledgeBaseArticle $existing = null): void
    {
        if (empty($validated['subcategory_id'])) {
            return;
        }
        $categoryId = $validated['category_id'] ?? $existing?->category_id;
        $belongs = KnowledgeBaseSubcategory::whereKey($validated['subcategory_id'])
            ->where('category_id', $categoryId)
            ->exists();

        if (!$belongs) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'The selected subcategory does not belong to the chosen category.',
                'errors' => ['subcategory_id' => ['The selected subcategory does not belong to the chosen category.']],
            ], 422));
        }
    }

    /**
     * Map validated input to model attributes.
     */
    private function articleAttributes(array $validated, ?KnowledgeBaseArticle $existing = null): array
    {
        $attributes = [];

        if (array_key_exists('title', $validated)) {
            $attributes['title'] = trim($validated['title']);
        }
        if (array_key_exists('category_id', $validated)) {
            $attributes['category_id'] = $validated['category_id'];
        }
        if (array_key_exists('subcategory_id', $validated)) {
            $attributes['subcategory_id'] = $validated['subcategory_id'] ?: null;
        }
        if (array_key_exists('summary', $validated)) {
            $attributes['summary'] = trim($validated['summary']);
        }
        if (array_key_exists('content_blocks', $validated)) {
            $attributes['content_blocks'] = array_values($validated['content_blocks']);
        }
        if (array_key_exists('attachments', $validated)) {
            $attributes['attachments'] = array_values($validated['attachments'] ?? []);
        }
        if (array_key_exists('cover_image_path', $validated)) {
            $attributes['cover_image_path'] = $this->nullIfBlank($validated['cover_image_path']);
        }
        if (array_key_exists('meta_title', $validated)) {
            $attributes['meta_title'] = $this->nullIfBlank($validated['meta_title']);
        }
        if (array_key_exists('meta_description', $validated)) {
            $attributes['meta_description'] = $this->nullIfBlank($validated['meta_description']);
        }

        // Keep the category name as the tag unless a tag was set explicitly
        if (array_key_exists('category_id', $validated)) {
            $newName = KnowledgeBaseCategory::whereKey($validated['category_id'])->value('name');
            $oldName = $existing?->category?->name;
            if (!$existing || !$existing->tag || $existing->tag === $oldName) {
                $attributes['tag'] = $newName;
            }
        }

        if (array_key_exists('status', $validated)) {
            $attributes['status'] = $validated['status'];
        } elseif (!$existing) {
            $attributes['status'] = 'draft';
        }

        if (!empty($validated['publish_date'])) {
            $attributes['published_at'] = Carbon::parse($validated['publish_date']);
        } elseif (($attributes['status'] ?? null) === 'published' && !($existing?->published_at)) {
            $attributes['published_at'] = now();
        }

        return $attributes;
    }

    private function syncRelated(KnowledgeBaseArticle $article, array $validated): void
    {
        if (!array_key_exists('related_article_ids', $validated)) {
            return;
        }
        $ids = array_values(array_diff($validated['related_article_ids'] ?? [], [$article->id]));
        $article->relatedArticles()->sync($ids);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'article';
        $slug = $base;
        $n = 2;
        while (
            KnowledgeBaseArticle::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    private function nullIfBlank($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function fileType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'svg', 'webp' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'doc',
            default => 'file',
        };
    }

    private function formatArticle(KnowledgeBaseArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'categoryId' => $article->category_id,
            'category' => $article->category?->name,
            'subcategoryId' => $article->subcategory_id,
            'subcategory' => $article->subcategory?->name,
            'tag' => $article->tag ?: ($article->category?->name ?? 'General'),
            'summary' => $article->summary ?? '',
            'status' => $article->status,
            'coverImagePath' => $article->cover_image_path,
            'coverUrl' => $article->cover_image_path ? Storage::disk('public')->url($article->cover_image_path) : null,
            'contentBlocks' => $article->content_blocks ?? [],
            'attachments' => $article->attachments ?? [],
            'metaTitle' => $article->meta_title,
            'metaDescription' => $article->meta_description,
            'relatedArticles' => $article->relatedArticles
                ->map(fn (KnowledgeBaseArticle $r) => ['id' => $r->id, 'title' => $r->title])
                ->values(),
            'views' => (int) $article->views,
            'helpfulCount' => (int) $article->helpful_count,
            'updatedAt' => $article->updated_at?->format('M j, Y'),
            'publishDate' => $article->published_at?->format('Y-m-d'),
            'publishedAt' => $article->published_at?->toIso8601String(),
        ];
    }
}
