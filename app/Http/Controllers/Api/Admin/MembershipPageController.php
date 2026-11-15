<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipPageContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MembershipPageController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'membership_page' => MembershipPageContent::present(MembershipPageContent::first()),
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $currentUser = $request->user();

            if (!$currentUser || !$currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage membership page content.',
                ], 403);
            }

            $validated = $this->validatedPayload($request);
            $content = MembershipPageContent::first();
            $validated = $this->handleUploads($request, $validated, $content);

            if ($content) {
                $content->fill($validated);
                $content->save();
            } else {
                $content = MembershipPageContent::create($validated);
            }

            return response()->json([
                'success' => true,
                'message' => 'Membership page content saved successfully.',
                'data' => [
                    'membership_page' => MembershipPageContent::present($content->fresh()),
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
                'message' => 'Membership page content save failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $currentUser = $request->user();

            if (!$currentUser || !$currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage membership page content.',
                ], 403);
            }

            $content = MembershipPageContent::find($id);

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Membership page content not found.',
                ], 404);
            }

            $validated = $this->validatedPayload($request);
            $validated = $this->handleUploads($request, $validated, $content);
            $content->fill($validated);
            $content->save();

            return response()->json([
                'success' => true,
                'message' => 'Membership page content updated successfully.',
                'data' => [
                    'membership_page' => MembershipPageContent::present($content->fresh()),
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
                'message' => 'Membership page content update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->hasAdminAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admins can delete membership page content.',
            ], 403);
        }

        $content = MembershipPageContent::find($id);

        if (!$content) {
            return response()->json([
                'success' => false,
                'message' => 'Membership page content not found.',
            ], 404);
        }

        $content->delete();

        return response()->json([
            'success' => true,
            'message' => 'Membership page content deleted successfully.',
        ], 200);
    }

    private function rules(): array
    {
        return [
            'backgrounds' => ['nullable', 'array'],
            'hero' => ['nullable', 'array'],
            'hero_features' => ['nullable', 'array'],
            'why_join' => ['nullable', 'array'],
            'stats' => ['nullable', 'array'],
            'plan_section' => ['nullable', 'array'],
            'plans' => ['nullable', 'array'],
            'benefits_section' => ['nullable', 'array'],
            'benefits' => ['nullable', 'array'],
            'bottom_cta' => ['nullable', 'array'],
            'faq_section' => ['nullable', 'array'],
            'faqs' => ['nullable', 'array'],
            'support_section' => ['nullable', 'array'],
            'hero_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:51200'],
            'support_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:51200'],
        ];
    }

    private function validatedPayload(Request $request): array
    {
        $jsonFields = [
            'backgrounds',
            'hero',
            'hero_features',
            'why_join',
            'stats',
            'plan_section',
            'plans',
            'benefits_section',
            'benefits',
            'bottom_cta',
            'faq_section',
            'faqs',
            'support_section',
        ];

        foreach ($jsonFields as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$field => $decoded]);
                }
            }
        }

        return $request->validate($this->rules());
    }

    private function handleUploads(Request $request, array $validated, ?MembershipPageContent $existing): array
    {
        if ($request->hasFile('hero_image')) {
            $this->deleteStoredImage($existing?->hero['image'] ?? null);
            $validated['hero'] = $validated['hero'] ?? [];
            $validated['hero']['image'] = '/storage/' . $request->file('hero_image')->store('membership-page', 'public');
        }

        if ($request->hasFile('support_image')) {
            $this->deleteStoredImage($existing?->support_section['image'] ?? null);
            $validated['support_section'] = $validated['support_section'] ?? [];
            $validated['support_section']['image'] = $request->file('support_image')->store('membership-page', 'public');
        }

        unset($validated['hero_image'], $validated['support_image']);

        return $validated;
    }

    private function deleteStoredImage(?string $path): void
    {
        if (!$path || str_starts_with($path, '/assets/') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = str_starts_with($path, '/storage/') ? substr($path, strlen('/storage/')) : ltrim($path, '/');
        Storage::disk('public')->delete($normalized);
    }
}
