<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\KnowledgeBaseSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Knowledge base categories and subcategories (the Categories admin screen).
 */
class KnowledgeBaseTaxonomyController extends Controller
{
    private const STATUSES = ['active', 'inactive'];

    /**
     * GET /api/admin/knowledge-base/categories
     * Query: q, status, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

        $query = KnowledgeBaseCategory::query()
            ->withCount(['subcategories', 'articles'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('meta_title', 'ilike', "%{$q}%")
                    ->orWhere('meta_description', 'ilike', "%{$q}%");
            });
        }

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (KnowledgeBaseCategory $c) => $this->formatCategory($c))->values(),
            'meta' => $this->meta($paginator),
        ]);
    }

    /**
     * POST /api/admin/knowledge-base/categories   (multipart: icon file required)
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $this->validateCategory($request);

        $iconPath = $request->file('icon')->store('knowledge-base/icons', 'public');

        $category = KnowledgeBaseCategory::create([
            'name' => trim($validated['name']),
            'slug' => $this->uniqueSlug(KnowledgeBaseCategory::class, $validated['name']),
            'meta_title' => $this->nullIfBlank($validated['meta_title'] ?? null),
            'meta_description' => $this->nullIfBlank($validated['meta_description'] ?? null),
            'sort_order' => (int) $validated['display_order'],
            'status' => $validated['status'],
            'icon_path' => $iconPath,
            'cover_image_path' => $this->storeCover($request),
            'icon' => 'folder',
            'tone' => 'purple',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $this->formatCategory($category->loadCount(['subcategories', 'articles'])),
        ], 201);
    }

    /**
     * PUT /api/admin/knowledge-base/categories/{id}   (multipart: icon optional)
     */
    public function updateCategory(Request $request, $id): JsonResponse
    {
        $category = KnowledgeBaseCategory::find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        $validated = $this->validateCategory($request, $category->id);

        $data = [
            'name' => trim($validated['name']),
            'meta_title' => $this->nullIfBlank($validated['meta_title'] ?? null),
            'meta_description' => $this->nullIfBlank($validated['meta_description'] ?? null),
            'sort_order' => (int) $validated['display_order'],
            'status' => $validated['status'],
        ];
        if ($data['name'] !== $category->name) {
            $data['slug'] = $this->uniqueSlug(KnowledgeBaseCategory::class, $data['name'], $category->id);
        }
        if ($cover = $this->storeCover($request)) {
            $data['cover_image_path'] = $cover;
        }
        if ($request->hasFile('icon')) {
            $data['icon_path'] = $request->file('icon')->store('knowledge-base/icons', 'public');
        }

        $category->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $this->formatCategory($category->fresh()->loadCount(['subcategories', 'articles'])),
        ]);
    }

    /**
     * DELETE /api/admin/knowledge-base/categories/{id}
     * Refuses to delete a category that still has articles, so no article is silently orphaned.
     */
    public function destroyCategory($id): JsonResponse
    {
        $category = KnowledgeBaseCategory::withCount('articles')->find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }
        if ($category->articles_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "This category has {$category->articles_count} article(s). Move or delete them first.",
            ], 422);
        }

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted successfully']);
    }

    /**
     * GET /api/admin/knowledge-base/subcategories
     * Query: q, category_id, status, page, per_page
     */
    public function subcategories(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

        $query = KnowledgeBaseSubcategory::query()
            ->with('category')
            ->orderBy('display_order')
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('meta_title', 'ilike', "%{$q}%")
                    ->orWhere('meta_description', 'ilike', "%{$q}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (KnowledgeBaseSubcategory $s) => $this->formatSubcategory($s))->values(),
            'meta' => $this->meta($paginator),
        ]);
    }

    /**
     * POST /api/admin/knowledge-base/subcategories   (multipart: icon file required)
     */
    public function storeSubcategory(Request $request): JsonResponse
    {
        $validated = $this->validateSubcategory($request);

        $iconPath = $request->file('icon')->store('knowledge-base/icons', 'public');

        $subcategory = KnowledgeBaseSubcategory::create([
            'category_id' => $validated['category_id'],
            'name' => trim($validated['name']),
            'slug' => $this->uniqueSlug(KnowledgeBaseSubcategory::class, $validated['name']),
            'meta_title' => $this->nullIfBlank($validated['meta_title'] ?? null),
            'meta_description' => $this->nullIfBlank($validated['meta_description'] ?? null),
            'display_order' => (int) $validated['display_order'],
            'status' => $validated['status'],
            'icon_path' => $iconPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sub category created successfully',
            'data' => $this->formatSubcategory($subcategory->load('category')),
        ], 201);
    }

    /**
     * PUT /api/admin/knowledge-base/subcategories/{id}   (multipart: icon optional)
     */
    public function updateSubcategory(Request $request, $id): JsonResponse
    {
        $subcategory = KnowledgeBaseSubcategory::find($id);
        if (!$subcategory) {
            return response()->json(['success' => false, 'message' => 'Sub category not found'], 404);
        }

        $validated = $this->validateSubcategory($request, $subcategory->id);

        $data = [
            'category_id' => $validated['category_id'],
            'name' => trim($validated['name']),
            'meta_title' => $this->nullIfBlank($validated['meta_title'] ?? null),
            'meta_description' => $this->nullIfBlank($validated['meta_description'] ?? null),
            'display_order' => (int) $validated['display_order'],
            'status' => $validated['status'],
        ];
        if ($data['name'] !== $subcategory->name) {
            $data['slug'] = $this->uniqueSlug(KnowledgeBaseSubcategory::class, $data['name'], $subcategory->id);
        }
        if ($request->hasFile('icon')) {
            $data['icon_path'] = $request->file('icon')->store('knowledge-base/icons', 'public');
        }

        $subcategory->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Sub category updated successfully',
            'data' => $this->formatSubcategory($subcategory->fresh()->load('category')),
        ]);
    }

    /**
     * DELETE /api/admin/knowledge-base/subcategories/{id}
     * Articles in the subcategory stay, with their subcategory cleared.
     */
    public function destroySubcategory($id): JsonResponse
    {
        $subcategory = KnowledgeBaseSubcategory::find($id);
        if (!$subcategory) {
            return response()->json(['success' => false, 'message' => 'Sub category not found'], 404);
        }

        $subcategory->delete();

        return response()->json(['success' => true, 'message' => 'Sub category deleted successfully']);
    }

    /**
     * GET /api/admin/knowledge-base/categories/options
     * Active categories with their active subcategories, for the article form dropdowns.
     */
    public function options(): JsonResponse
    {
        $categories = KnowledgeBaseCategory::query()
            ->where('status', 'active')
            ->with(['subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('display_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (KnowledgeBaseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /* ----------------------------------------------------------------------- */

    private function validateCategory(Request $request, ?int $ignoreId = null): array
    {
        $isCreate = $ignoreId === null;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('knowledge_base_categories', 'name')->ignore($ignoreId),
            ],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'display_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'icon' => [$isCreate ? 'required' : 'nullable', 'file', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
            'cover_image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:5120'],
        ]);
    }

    private function validateSubcategory(Request $request, ?int $ignoreId = null): array
    {
        $isCreate = $ignoreId === null;

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('knowledge_base_categories', 'id')],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('knowledge_base_subcategories', 'name')
                    ->where(fn ($q) => $q->where('category_id', $request->input('category_id')))
                    ->ignore($ignoreId),
            ],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'display_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'icon' => [$isCreate ? 'required' : 'nullable', 'file', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ]);

        return $validated;
    }

    private function uniqueSlug(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $n = 2;
        while (
            $modelClass::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    private function storeCover(Request $request): ?string
    {
        return $request->file('cover_image')?->store('knowledge-base/covers', 'public');
    }

    private function nullIfBlank($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function iconUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function formatCategory(KnowledgeBaseCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'metaTitle' => $c->meta_title,
            'metaDescription' => $c->meta_description,
            'displayOrder' => (int) $c->sort_order,
            'status' => $c->status,
            'iconUrl' => $this->iconUrl($c->icon_path),
            'coverUrl' => $this->iconUrl($c->cover_image_path),
            'subcategoryCount' => (int) ($c->subcategories_count ?? 0),
            'articleCount' => (int) ($c->articles_count ?? 0),
        ];
    }

    private function formatSubcategory(KnowledgeBaseSubcategory $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'categoryId' => (int) $s->category_id,
            'categoryName' => $s->category?->name,
            'metaTitle' => $s->meta_title,
            'metaDescription' => $s->meta_description,
            'displayOrder' => (int) $s->display_order,
            'status' => $s->status,
            'iconUrl' => $this->iconUrl($s->icon_path),
        ];
    }
}
