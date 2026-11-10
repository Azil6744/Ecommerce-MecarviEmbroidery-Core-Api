<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CMS for the banners at the top of the public Knowledge Base pages.
 */
class KnowledgeBaseBannerController extends Controller
{
    private const ICONS = ['book', 'gear', 'clock', 'check', 'star'];
    private const TONES = ['purple', 'pink', 'orange', 'blue', 'green'];

    /** Copy shown until an admin changes it. */
    public static function defaults(): array
    {
        return [
            'hub' => [
                'eyebrow' => 'KNOWLEDGE BASE',
                'title' => 'Your Embroidery Knowledge Hub',
                'highlight' => 'Hub',
                'description' => 'Expert guides, tips and resources to help you achieve perfect embroidery results.',
                'search_placeholder' => 'Search for articles...',
                'features' => [
                    ['title' => 'Expert Guides', 'description' => 'Step-by-step helpful articles', 'icon' => 'book', 'tone' => 'purple'],
                    ['title' => 'Industry Knowledge', 'description' => 'Best practices from embroidery experts', 'icon' => 'gear', 'tone' => 'pink'],
                    ['title' => 'Always Updated', 'description' => 'New techniques and solutions added regularly', 'icon' => 'clock', 'tone' => 'orange'],
                ],
            ],
            'topics' => [
                'eyebrow' => 'KNOWLEDGE BASE',
                'title' => 'All Topics',
                'highlight' => 'Topics',
                'description' => 'Browse all categories and find helpful articles, guides and resources for your embroidery projects.',
                'search_placeholder' => 'Search articles...',
                'features' => [],
            ],
            'article' => [
                'eyebrow' => 'KNOWLEDGE BASE',
                'title' => 'Helpful Articles & Guides',
                'highlight' => null,
                'description' => 'Find everything you need to know about our embroidery processes, file requirements and best practices.',
                'search_placeholder' => 'Search articles...',
                'features' => [],
            ],
        ];
    }

    /**
     * GET /api/admin/knowledge-base/banners
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->ensureBanners()->map(fn (KnowledgeBaseBanner $b) => $this->format($b))->values(),
        ]);
    }

    /**
     * POST|PUT /api/admin/knowledge-base/banners/{pageKey}
     * Multipart fields: eyebrow, title, highlight, description, search_placeholder, is_active,
     * features (JSON list), image (file), remove_image (1|0)
     */
    public function update(Request $request, string $pageKey): JsonResponse
    {
        if (!in_array($pageKey, KnowledgeBaseBanner::PAGE_KEYS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown banner page'], 404);
        }

        $validated = $request->validate([
            'eyebrow' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:120'],
            'highlight' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:300'],
            'search_placeholder' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'features' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $this->ensureBanners();
        $banner = KnowledgeBaseBanner::where('page_key', $pageKey)->firstOrFail();

        $features = [];
        if (!empty($validated['features'])) {
            $decoded = json_decode($validated['features'], true);
            if (!is_array($decoded)) {
                return response()->json(['success' => false, 'message' => 'Features must be a valid list.'], 422);
            }
            $features = collect($decoded)
                ->filter(fn ($f) => is_array($f) && trim((string) ($f['title'] ?? '')) !== '')
                ->take(6)
                ->map(fn ($f) => [
                    'title' => trim((string) $f['title']),
                    'description' => trim((string) ($f['description'] ?? '')),
                    'icon' => in_array($f['icon'] ?? '', self::ICONS, true) ? $f['icon'] : 'book',
                    'tone' => in_array($f['tone'] ?? '', self::TONES, true) ? $f['tone'] : 'purple',
                ])
                ->values()
                ->all();
        }

        $data = [
            'eyebrow' => $this->nullIfBlank($validated['eyebrow'] ?? null),
            'title' => trim($validated['title']),
            'highlight' => $this->nullIfBlank($validated['highlight'] ?? null),
            'description' => $this->nullIfBlank($validated['description'] ?? null),
            'search_placeholder' => $this->nullIfBlank($validated['search_placeholder'] ?? null),
            'features' => $features,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        if ($request->hasFile('image')) {
            $this->deleteImage($banner->image_path);
            $data['image_path'] = $request->file('image')->store('knowledge-base/banners', 'public');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($banner->image_path);
            $data['image_path'] = null;
        }

        $banner->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'Banner saved successfully',
            'data' => $this->format($banner->fresh()),
        ]);
    }

    /** Creates the three banner rows (with default copy) the first time they are needed. */
    private function ensureBanners()
    {
        foreach (self::defaults() as $key => $copy) {
            KnowledgeBaseBanner::firstOrCreate(['page_key' => $key], array_merge($copy, ['is_active' => true]));
        }

        return KnowledgeBaseBanner::whereIn('page_key', KnowledgeBaseBanner::PAGE_KEYS)
            ->get()
            ->sortBy(fn ($b) => array_search($b->page_key, KnowledgeBaseBanner::PAGE_KEYS, true))
            ->values();
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function nullIfBlank($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function format(KnowledgeBaseBanner $b): array
    {
        return [
            'pageKey' => $b->page_key,
            'eyebrow' => $b->eyebrow,
            'title' => $b->title,
            'highlight' => $b->highlight,
            'description' => $b->description,
            'searchPlaceholder' => $b->search_placeholder,
            'features' => $b->features ?? [],
            'imagePath' => $b->image_path,
            'imageUrl' => $b->image_path ? Storage::disk('public')->url($b->image_path) : null,
            'isActive' => (bool) $b->is_active,
        ];
    }
}
