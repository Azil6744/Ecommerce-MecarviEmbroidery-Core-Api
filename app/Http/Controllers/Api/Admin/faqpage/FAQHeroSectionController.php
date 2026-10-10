<?php

namespace App\Http\Controllers\Api\Admin\faqpage;

use App\Http\Controllers\Controller;
use App\Models\FAQHeroSection;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FAQHeroSectionController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get FAQ hero section content.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $section = FAQHeroSection::first();

        if (!$section) {
            return response()->json([
                'success' => true,
                'data' => [
                    'faq_hero_section' => null,
                    'message' => 'FAQ hero section not configured yet.',
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'faq_hero_section' => [
                    'id' => $section->id,
                    'badge_title' => $section->badge_title ?? 'SUPPORT CENTER',
                    'hero_title' => $section->hero_title ?? 'Frequently Asked Questions',
                    'hero_image' => $section->hero_image,
                    'created_at' => $section->created_at,
                    'updated_at' => $section->updated_at,
                ],
            ],
        ], 200);
    }

    /**
     * Create or update FAQ hero section content.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            if (!$currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage FAQ hero section content.',
                ], 403);
            }

            $validated = $request->validate([
                'badge_title' => ['nullable', 'string', 'max:255'],
                'hero_title' => ['nullable', 'string', 'max:255'],
                'hero_image' => ['nullable', 'string', 'max:1000'],
            ]);

            $section = FAQHeroSection::updateOrCreate(
                ['id' => FAQHeroSection::first()?->id ?? 0],
                $validated
            );

            $this->broadcastContentUpdate('faq-hero-section', 'updated', [
                'id' => $section->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ hero section updated successfully',
                'data' => [
                    'faq_hero_section' => [
                        'id' => $section->id,
                        'badge_title' => $section->badge_title,
                        'hero_title' => $section->hero_title,
                        'hero_image' => $section->hero_image,
                        'updated_at' => $section->updated_at,
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ hero section update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ hero section update.',
            ], 500);
        }
    }

    /**
     * Update FAQ hero section content.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            if (!$currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage FAQ hero section content.',
                ], 403);
            }

            $section = FAQHeroSection::find($id);

            if (!$section) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ hero section not found.',
                ], 404);
            }

            $dataToUpdate = [];

            if ($request->has('badge_title')) {
                $dataToUpdate['badge_title'] = $request->input('badge_title');
            }
            if ($request->has('hero_title')) {
                $dataToUpdate['hero_title'] = $request->input('hero_title');
            }
            if ($request->has('hero_image')) {
                $dataToUpdate['hero_image'] = $request->input('hero_image');
            }

            if (!empty($dataToUpdate)) {
                $rules = [
                    'badge_title' => ['nullable', 'string', 'max:255'],
                    'hero_title' => ['nullable', 'string', 'max:255'],
                    'hero_image' => ['nullable', 'string', 'max:1000'],
                ];

                $request->validate($rules);

                $section->fill($dataToUpdate);
                $section->save();
                $section->refresh();

                $this->broadcastContentUpdate('faq-hero-section', 'updated', [
                    'id' => $section->id,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided to update.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAQ hero section updated successfully',
                'data' => [
                    'faq_hero_section' => [
                        'id' => $section->id,
                        'badge_title' => $section->badge_title,
                        'hero_title' => $section->hero_title,
                        'hero_image' => $section->hero_image,
                        'updated_at' => $section->updated_at,
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ hero section update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ hero section update.',
            ], 500);
        }
    }

    /**
     * Show a specific FAQ hero section by ID.
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $section = FAQHeroSection::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ hero section not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'faq_hero_section' => [
                    'id' => $section->id,
                    'badge_title' => $section->badge_title ?? 'SUPPORT CENTER',
                    'hero_title' => $section->hero_title,
                    'hero_image' => $section->hero_image,
                    'created_at' => $section->created_at,
                    'updated_at' => $section->updated_at,
                ],
            ],
        ], 200);
    }

    /**
     * Delete FAQ hero section content.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            if (!$currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can delete FAQ hero section content.',
                ], 403);
            }

            $section = FAQHeroSection::find($id);

            if (!$section) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ hero section not found.',
                ], 404);
            }

            $sectionId = $section->id;
            $section->delete();

            $this->broadcastContentUpdate('faq-hero-section', 'deleted', [
                'id' => $sectionId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ hero section deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ hero section deletion failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ hero section deletion.',
            ], 500);
        }
    }
}
