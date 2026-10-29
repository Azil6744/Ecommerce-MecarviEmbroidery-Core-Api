<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PolicySection;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PolicySectionController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get all policy sections (public).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $sections = PolicySection::where('is_published', true)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'policy_sections' => $sections->map(function ($section) {
                    return $this->formatSection($section);
                }),
            ],
        ], 200);
    }

    /**
     * Get all policy sections including unpublished (admin).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function adminIndex()
    {
        $sections = PolicySection::all();

        return response()->json([
            'success' => true,
            'data' => [
                'policy_sections' => $sections->map(function ($section) {
                    return $this->formatSection($section);
                }),
            ],
        ], 200);
    }

    /**
     * Show a specific policy section by ID (public).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $section = PolicySection::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Policy section not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'policy_section' => $this->formatSection($section),
            ],
        ], 200);
    }

    /**
     * Show a specific policy section by type (public).
     *
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function showByType($type)
    {
        $section = PolicySection::where('type', $type)
            ->where('is_published', true)
            ->first();

        if (!$section) {
            return response()->json([
                'success' => true,
                'data' => [
                    'policy_section' => null,
                    'message' => 'Policy section not configured yet.',
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'policy_section' => $this->formatSection($section),
            ],
        ], 200);
    }

    /**
     * Create or update a policy section (admin only).
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
                    'message' => 'Unauthorized. Only admins can manage policy sections.',
                ], 403);
            }

            $validated = $request->validate([
                'type' => ['required', 'string', 'in:terms,privacy,warranty,financing,membership,policy,general'],
                'hero_title' => ['nullable', 'string', 'max:255'],
                'hero_subtitle' => ['nullable', 'string', 'max:500'],
                'hero_background_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:51200'],
                'sections' => ['nullable', 'string'], // JSON string
                'styling' => ['nullable', 'string'], // JSON string
                'is_published' => ['nullable', 'boolean'],
            ]);

            // Handle JSON sections
            if (isset($validated['sections']) && is_string($validated['sections'])) {
                $decoded = json_decode($validated['sections'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['sections'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid JSON format for sections.',
                    ], 422);
                }
            }

            // Handle JSON styling
            if (isset($validated['styling']) && is_string($validated['styling'])) {
                $decodedStyling = json_decode($validated['styling'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['styling'] = $decodedStyling;
                }
            }

            // Check if a policy of this type already exists
            $existing = PolicySection::where('type', $validated['type'])->first();

            // Handle image upload
            if ($request->hasFile('hero_background_image')) {
                if ($existing && $existing->hero_background_image) {
                    Storage::disk('public')->delete($existing->hero_background_image);
                }
                $validated['hero_background_image'] = $request->file('hero_background_image')
                    ->store('policy-sections', 'public');
            } elseif ($existing) {
                $validated['hero_background_image'] = $existing->hero_background_image;
            }

            // Update or create
            $section = PolicySection::updateOrCreate(
                ['type' => $validated['type']],
                $validated
            );

            $this->broadcastContentUpdate('policy-section', 'updated', [
                'id' => $section->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Policy section saved successfully.',
                'data' => [
                    'policy_section' => $this->formatSection($section),
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
                'message' => 'Policy section save failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Update a specific policy section (admin only).
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
                    'message' => 'Unauthorized. Only admins can manage policy sections.',
                ], 403);
            }

            $section = PolicySection::find($id);

            if (!$section) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy section not found.',
                ], 404);
            }

            $validated = $request->validate([
                'hero_title' => ['nullable', 'string', 'max:255'],
                'hero_subtitle' => ['nullable', 'string', 'max:500'],
                'hero_background_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:51200'],
                'sections' => ['nullable', 'string'], // JSON string
                'styling' => ['nullable', 'string'], // JSON string
                'is_published' => ['nullable', 'boolean'],
            ]);

            // Handle JSON sections
            if (isset($validated['sections']) && is_string($validated['sections'])) {
                $decoded = json_decode($validated['sections'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['sections'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid JSON format for sections.',
                    ], 422);
                }
            }

            // Handle JSON styling
            if (isset($validated['styling']) && is_string($validated['styling'])) {
                $decodedStyling = json_decode($validated['styling'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['styling'] = $decodedStyling;
                }
            }

            // Handle image upload
            if ($request->hasFile('hero_background_image')) {
                if ($section->hero_background_image) {
                    Storage::disk('public')->delete($section->hero_background_image);
                }
                $validated['hero_background_image'] = $request->file('hero_background_image')
                    ->store('policy-sections', 'public');
            } else {
                unset($validated['hero_background_image']);
            }

            $section->fill($validated);
            $section->save();
            $section->refresh();

            $this->broadcastContentUpdate('policy-section', 'updated', [
                'id' => $section->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Policy section updated successfully.',
                'data' => [
                    'policy_section' => $this->formatSection($section),
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
                'message' => 'Policy section update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Delete a policy section (admin only).
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
                    'message' => 'Unauthorized. Only admins can delete policy sections.',
                ], 403);
            }

            $section = PolicySection::find($id);

            if (!$section) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy section not found.',
                ], 404);
            }

            if ($section->hero_background_image) {
                Storage::disk('public')->delete($section->hero_background_image);
            }

            $sectionId = $section->id;
            $section->delete();

            $this->broadcastContentUpdate('policy-section', 'deleted', [
                'id' => $sectionId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Policy section deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Policy section deletion failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Format a policy section for response.
     *
     * @param PolicySection $section
     * @return array
     */
    private function formatSection(PolicySection $section)
    {
        return [
            'id' => $section->id,
            'type' => $section->type,
            'hero_title' => $section->hero_title,
            'hero_subtitle' => $section->hero_subtitle,
            'hero_background_image' => $section->hero_background_image_url,
            'sections' => $section->sections ?? [],
            'styling' => $section->styling ?? null,
            'is_published' => $section->is_published,
            'created_at' => $section->created_at,
            'updated_at' => $section->updated_at,
        ];
    }
}
