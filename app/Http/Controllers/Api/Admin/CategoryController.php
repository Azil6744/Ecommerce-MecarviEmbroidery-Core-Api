<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $hasParentId = Schema::hasColumn('categories', 'parent_id');
        $hasSortOrder = Schema::hasColumn('categories', 'sort_order');
        $hasProductTypes = Schema::hasColumn('categories', 'product_types');
        $productTypeFilter = $request->query('product_type');

        $columns = collect([
            'id',
            'name',
            'slug',
            'description',
            'image',
            'seo_meta_title',
            'seo_meta_description',
            'parent_id',
            'product_types',
            'is_active',
            'sort_order',
            'created_at',
            'updated_at',
        ])->filter(fn ($column) => Schema::hasColumn('categories', $column))->values()->all();

        $rows = DB::table('categories')
            ->select($columns)
            ->when($hasSortOrder, fn ($query) => $query->orderBy('sort_order'))
            ->orderBy('name')
            ->get()
            ->map(function ($category) use ($hasParentId) {
                $category = (array) $category;
                $category['parent_id'] = $hasParentId ? ($category['parent_id'] ?? null) : null;
                if (isset($category['product_types']) && is_string($category['product_types'])) {
                    $category['product_types'] = json_decode($category['product_types'], true) ?? [];
                } elseif (!isset($category['product_types']) || !is_array($category['product_types'])) {
                    $category['product_types'] = ['physical'];
                }
                $category['children'] = [];

                return $category;
            });

        if ($productTypeFilter && $hasProductTypes) {
            $hasType = function ($cat) use ($productTypeFilter) {
                $types = $cat['product_types'] ?? [];
                if (is_string($types)) {
                    $types = json_decode($types, true) ?? [];
                }
                return in_array($productTypeFilter, (array) $types, true);
            };

            // Parent should be included if parent matches or if any child matches
            $matchingChildParentIds = $rows->filter(fn ($cat) => $cat['parent_id'] !== null && $hasType($cat))->pluck('parent_id')->all();

            $rows = $rows->filter(function ($cat) use ($hasType, $matchingChildParentIds) {
                if ($cat['parent_id'] === null) {
                    return $hasType($cat) || in_array($cat['id'], $matchingChildParentIds, true);
                }
                return $hasType($cat);
            })->values();
        }

        if (! $hasParentId) {
            return response()->json($rows->values());
        }

        $childrenByParent = $rows->groupBy('parent_id');
        $categories = $rows
            ->whereNull('parent_id')
            ->map(function (array $category) use ($childrenByParent) {
                $category['children'] = $childrenByParent->get($category['id'], collect())->values();

                return $category;
            })
            ->values();

        return response()->json($categories);
    }

    /**
     * Resolve the image field: handle file upload or base64 data URL.
     * Returns the storage path, or null if no image provided.
     * Pass $oldPath to delete the existing image when replacing.
     */
    private function resolveImage(Request $request, ?string $oldPath = null): ?string
    {
        if ($request->hasFile('image')) {
            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
            return $request->file('image')->store('categories', 'public');
        }

        if ($request->has('image') && is_string($request->input('image'))) {
            $imageString = $request->input('image');

            if (preg_match('/^data:image\/(\w+);base64,/', $imageString, $matches)) {
                $imageType = $matches[1];
                $imageData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imageString));

                if ($imageData !== false) {
                    if ($oldPath) {
                        Storage::disk('public')->delete($oldPath);
                    }
                    $filename = 'category_' . time() . '_' . Str::random(6) . '.' . $imageType;
                    $imagePath = 'categories/' . $filename;
                    Storage::disk('public')->put($imagePath, $imageData);
                    return $imagePath;
                }
            }

            // Plain URL string (e.g. existing path kept as-is)
            if (!str_starts_with($imageString, 'data:')) {
                return $imageString ?: null;
            }
        }

        return null;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable',
            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'product_types' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $imagePath = $this->resolveImage($request);

        $productTypes = $request->input('product_types');
        if (!is_array($productTypes) || empty($productTypes)) {
            if ($request->filled('parent_id')) {
                $parent = Category::find($request->parent_id);
                $productTypes = $parent?->product_types ?? ['physical'];
            } else {
                $productTypes = ['physical'];
            }
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => $request->slug ? Str::slug($request->slug) : Str::slug($request->name),
            'description' => $request->description,
            'image' => $imagePath,
            'seo_meta_title' => $request->seo_meta_title,
            'seo_meta_description' => $request->seo_meta_description,
            'parent_id' => $request->parent_id,
            'product_types' => $productTypes,
            'is_active' => $request->is_active ?? true,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json($category, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        return response()->json($category->load('children'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable',
            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'product_types' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $imagePath = $this->resolveImage($request, $category->image);
        if ($imagePath === null && !$request->has('image')) {
            $imagePath = $category->image; // keep existing if not provided
        }

        $updateData = [
            'name' => $request->name,
            'slug' => $request->slug ? Str::slug($request->slug) : Str::slug($request->name),
            'description' => $request->description,
            'image' => $imagePath,
            'seo_meta_title' => $request->seo_meta_title,
            'seo_meta_description' => $request->seo_meta_description,
            'parent_id' => $request->parent_id,
            'is_active' => $request->is_active ?? $category->is_active,
            'sort_order' => $request->sort_order ?? $category->sort_order,
        ];

        if ($request->has('product_types')) {
            $types = $request->input('product_types');
            $updateData['product_types'] = is_array($types) ? $types : ['physical'];
        } elseif ($request->filled('parent_id')) {
            $parent = Category::find($request->parent_id);
            if ($parent && !empty($parent->product_types)) {
                $updateData['product_types'] = $parent->product_types;
            }
        }

        $category->update($updateData);

        return response()->json($category->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        if ($category->image) {
            Storage::disk('public')->delete($category->image);
        }
        $category->delete();
        return response()->json(['message' => 'Category deleted successfully']);
    }
}

