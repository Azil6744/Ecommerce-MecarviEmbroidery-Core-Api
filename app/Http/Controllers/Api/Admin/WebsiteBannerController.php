<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebsiteBanner;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WebsiteBannerController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get all Website banners (Public & Admin).
     */
    public function index(Request $request)
    {
        try {
            $dbBanners = WebsiteBanner::all()->keyBy('page_key');
            $defaultBanners = WebsiteBanner::getDefaults();

            $result = [];
            foreach ($defaultBanners as $pageKey => $def) {
                if (isset($dbBanners[$pageKey])) {
                    $banner = $dbBanners[$pageKey];
                    $result[$pageKey] = [
                        'id' => $banner->id,
                        'page_key' => $banner->page_key,
                        'page_name' => $def['page_name'] ?? ucfirst($banner->page_key),
                        'category' => $def['category'] ?? 'General',
                        'title' => $banner->title ?? $def['title'],
                        'subtitle' => $banner->subtitle ?? $def['subtitle'],
                        'description' => $banner->description ?? $def['description'],
                        'footer_text' => $banner->footer_text ?? $def['footer_text'],
                        'badge_text' => $banner->badge_text ?? $def['badge_text'],
                        'badge_subtext' => $banner->badge_subtext ?? $def['badge_subtext'],
                        'button_text' => $banner->button_text ?? $def['button_text'],
                        'button_url' => $banner->button_url ?? $def['button_url'],
                        'image_url' => $banner->image_url,
                        'image_path' => $banner->image_path,
                        'preset_image_url' => $banner->preset_image_url,
                        'theme_color' => $banner->theme_color ?? $def['theme_color'],
                        'accent_color' => $banner->accent_color ?? $def['accent_color'],
                        'is_active' => (bool)$banner->is_active,
                        'is_customized' => true,
                        'updated_at' => $banner->updated_at ? $banner->updated_at->toISOString() : null,
                    ];
                } else {
                    $result[$pageKey] = [
                        'id' => null,
                        'page_key' => $pageKey,
                        'page_name' => $def['page_name'] ?? ucfirst($pageKey),
                        'category' => $def['category'] ?? 'General',
                        'title' => $def['title'],
                        'subtitle' => $def['subtitle'],
                        'description' => $def['description'],
                        'footer_text' => $def['footer_text'],
                        'badge_text' => $def['badge_text'],
                        'badge_subtext' => $def['badge_subtext'],
                        'button_text' => $def['button_text'],
                        'button_url' => $def['button_url'],
                        'image_url' => $def['image_url'],
                        'image_path' => null,
                        'preset_image_url' => $def['image_url'],
                        'theme_color' => $def['theme_color'],
                        'accent_color' => $def['accent_color'],
                        'is_active' => (bool)$def['is_active'],
                        'is_customized' => false,
                        'updated_at' => null,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'banners' => $result,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch website banners: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get banner for a specific website page.
     */
    public function show(string $pageKey)
    {
        try {
            $banner = WebsiteBanner::where('page_key', $pageKey)->first();
            $defaults = WebsiteBanner::getDefaultsForPage($pageKey);

            if ($banner) {
                $data = [
                    'id' => $banner->id,
                    'page_key' => $banner->page_key,
                    'page_name' => $defaults['page_name'] ?? ucfirst($banner->page_key),
                    'category' => $defaults['category'] ?? 'General',
                    'title' => $banner->title ?? $defaults['title'],
                    'subtitle' => $banner->subtitle ?? $defaults['subtitle'],
                    'description' => $banner->description ?? $defaults['description'],
                    'footer_text' => $banner->footer_text ?? $defaults['footer_text'],
                    'badge_text' => $banner->badge_text ?? $defaults['badge_text'],
                    'badge_subtext' => $banner->badge_subtext ?? $defaults['badge_subtext'],
                    'button_text' => $banner->button_text ?? $defaults['button_text'],
                    'button_url' => $banner->button_url ?? $defaults['button_url'],
                    'image_url' => $banner->image_url,
                    'image_path' => $banner->image_path,
                    'preset_image_url' => $banner->preset_image_url,
                    'theme_color' => $banner->theme_color ?? $defaults['theme_color'],
                    'accent_color' => $banner->accent_color ?? $defaults['accent_color'],
                    'is_active' => (bool)$banner->is_active,
                    'is_customized' => true,
                    'updated_at' => $banner->updated_at ? $banner->updated_at->toISOString() : null,
                ];
            } else {
                $data = [
                    'id' => null,
                    'page_key' => $pageKey,
                    'page_name' => $defaults['page_name'] ?? ucfirst($pageKey),
                    'category' => $defaults['category'] ?? 'General',
                    'title' => $defaults['title'],
                    'subtitle' => $defaults['subtitle'],
                    'description' => $defaults['description'],
                    'footer_text' => $defaults['footer_text'],
                    'badge_text' => $defaults['badge_text'],
                    'badge_subtext' => $defaults['badge_subtext'],
                    'button_text' => $defaults['button_text'],
                    'button_url' => $defaults['button_url'],
                    'image_url' => $defaults['image_url'],
                    'image_path' => null,
                    'preset_image_url' => $defaults['image_url'],
                    'theme_color' => $defaults['theme_color'],
                    'accent_color' => $defaults['accent_color'],
                    'is_active' => (bool)$defaults['is_active'],
                    'is_customized' => false,
                    'updated_at' => null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'banner' => $data,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch website banner: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store or update a Website Banner.
     */
    public function store(Request $request, ?string $pageKey = null)
    {
        try {
            $key = $pageKey ?: $request->input('page_key');
            if (empty($key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Page key is required.',
                ], 422);
            }

            $validated = $request->validate([
                'page_key' => 'nullable|string|max:100',
                'title' => 'nullable|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'footer_text' => 'nullable|string|max:255',
                'badge_text' => 'nullable|string|max:255',
                'badge_subtext' => 'nullable|string|max:255',
                'button_text' => 'nullable|string|max:100',
                'button_url' => 'nullable|string|max:255',
                'preset_image_url' => 'nullable|string|max:1000',
                'theme_color' => 'nullable|string|max:50',
                'accent_color' => 'nullable|string|max:50',
                'is_active' => 'nullable',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:51200',
            ]);

            $banner = WebsiteBanner::firstOrNew(['page_key' => $key]);
            $defaults = WebsiteBanner::getDefaultsForPage($key);

            // Handle file upload
            if ($request->hasFile('image')) {
                // Delete previous file if exists
                if ($banner->image_path && Storage::disk('public')->exists($banner->image_path)) {
                    Storage::disk('public')->delete($banner->image_path);
                }

                $path = $request->file('image')->store('website-banners', 'public');
                $banner->image_path = $path;
                $banner->preset_image_url = null;
            } elseif ($request->has('preset_image_url') && !empty($request->input('preset_image_url'))) {
                $banner->preset_image_url = $request->input('preset_image_url');
            }

            if ($request->has('title')) {
                $banner->title = $validated['title'] ?? '';
            }
            if ($request->has('subtitle')) {
                $banner->subtitle = $validated['subtitle'] ?? '';
            }
            if ($request->has('description')) {
                $banner->description = $validated['description'] ?? '';
            }
            if ($request->has('footer_text')) {
                $banner->footer_text = $validated['footer_text'] ?? '';
            }
            if ($request->has('badge_text')) {
                $banner->badge_text = $validated['badge_text'] ?? '';
            }
            if ($request->has('badge_subtext')) {
                $banner->badge_subtext = $validated['badge_subtext'] ?? '';
            }
            if ($request->has('button_text')) {
                $banner->button_text = $validated['button_text'] ?? '';
            }
            if ($request->has('button_url')) {
                $banner->button_url = $validated['button_url'] ?? '';
            }
            if ($request->has('theme_color')) {
                $banner->theme_color = $validated['theme_color'] ?? ($defaults['theme_color'] ?? '#059669');
            } elseif (!$banner->exists) {
                $banner->theme_color = $defaults['theme_color'] ?? '#059669';
            }
            if ($request->has('accent_color')) {
                $banner->accent_color = $validated['accent_color'] ?? ($defaults['accent_color'] ?? '#10b981');
            } elseif (!$banner->exists) {
                $banner->accent_color = $defaults['accent_color'] ?? '#10b981';
            }
            if ($request->has('is_active')) {
                $banner->is_active = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            }

            $banner->save();

            $this->broadcastContentUpdate('website-banners', 'updated', [
                'page_key' => $banner->page_key,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Website banner saved successfully.',
                'data' => [
                    'banner' => [
                        'id' => $banner->id,
                        'page_key' => $banner->page_key,
                        'page_name' => $defaults['page_name'] ?? ucfirst($banner->page_key),
                        'category' => $defaults['category'] ?? 'General',
                        'title' => $banner->title,
                        'subtitle' => $banner->subtitle,
                        'description' => $banner->description,
                        'footer_text' => $banner->footer_text,
                        'badge_text' => $banner->badge_text,
                        'badge_subtext' => $banner->badge_subtext,
                        'button_text' => $banner->button_text,
                        'button_url' => $banner->button_url,
                        'image_url' => $banner->image_url,
                        'image_path' => $banner->image_path,
                        'preset_image_url' => $banner->preset_image_url,
                        'theme_color' => $banner->theme_color,
                        'accent_color' => $banner->accent_color,
                        'is_active' => (bool)$banner->is_active,
                        'is_customized' => true,
                        'updated_at' => $banner->updated_at ? $banner->updated_at->toISOString() : null,
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save website banner: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset a website banner to default settings.
     */
    public function reset(string $pageKey)
    {
        try {
            $banner = WebsiteBanner::where('page_key', $pageKey)->first();

            if ($banner) {
                if ($banner->image_path && Storage::disk('public')->exists($banner->image_path)) {
                    Storage::disk('public')->delete($banner->image_path);
                }
                $banner->delete();
            }

            $defaults = WebsiteBanner::getDefaultsForPage($pageKey);

            $this->broadcastContentUpdate('website-banners', 'reset', [
                'page_key' => $pageKey,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Website banner reset to default successfully.',
                'data' => [
                    'banner' => [
                        'id' => null,
                        'page_key' => $pageKey,
                        'page_name' => $defaults['page_name'] ?? ucfirst($pageKey),
                        'category' => $defaults['category'] ?? 'General',
                        'title' => $defaults['title'],
                        'subtitle' => $defaults['subtitle'],
                        'description' => $defaults['description'],
                        'footer_text' => $defaults['footer_text'],
                        'badge_text' => $defaults['badge_text'],
                        'badge_subtext' => $defaults['badge_subtext'],
                        'button_text' => $defaults['button_text'],
                        'button_url' => $defaults['button_url'],
                        'image_url' => $defaults['image_url'],
                        'image_path' => null,
                        'preset_image_url' => $defaults['image_url'],
                        'theme_color' => $defaults['theme_color'],
                        'accent_color' => $defaults['accent_color'],
                        'is_active' => (bool)$defaults['is_active'],
                        'is_customized' => false,
                        'updated_at' => null,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset website banner: ' . $e->getMessage(),
            ], 500);
        }
    }
}
