<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormPageSetting;
use App\Models\QuoteFormField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteFormFieldController extends Controller
{
    private function getEquivalentSlugs(?string $slug): array
    {
        if (!$slug) return ['quote'];
        $s = strtolower(trim($slug));
        if (in_array($s, ['vendor-application', 'vendor-applications', 'vendor'])) {
            return ['vendor-applications', 'vendor-application'];
        }
        if (in_array($s, ['financing-application', 'financing'])) {
            return ['financing-application', 'financing'];
        }
        if (in_array($s, ['scholarship-application', 'scholarship', 'scholarships'])) {
            return ['scholarship-application', 'scholarship'];
        }
        if (in_array($s, ['sponsorship-application', 'sponsorship', 'sponsorships'])) {
            return ['sponsorship', 'sponsorship-application'];
        }
        if (in_array($s, ['career-application', 'career', 'careers'])) {
            return ['career-application', 'career'];
        }
        if (in_array($s, ['warranty-application', 'warranty', 'warranties'])) {
            return ['warranty', 'warranty-application'];
        }
        if (in_array($s, ['quote', 'quotations'])) {
            return ['quote', 'quotations'];
        }
        return [$s];
    }

    /**
     * Public: Get all active form fields grouped by section.
     */
    public function publicIndex()
    {
        $rawSlug = request('page', 'quote') ?: 'quote';
        $equivalentSlugs = $this->getEquivalentSlugs($rawSlug);

        // Find the first equivalent slug that actually has active fields to avoid cross-slug duplication
        $targetSlug = null;
        foreach ($equivalentSlugs as $candidate) {
            if (QuoteFormField::where('is_active', true)->where('page_slug', $candidate)->exists()) {
                $targetSlug = $candidate;
                break;
            }
        }
        $targetSlug = $targetSlug ?: $equivalentSlugs[0];

        $fields = QuoteFormField::where('is_active', true)
            ->where('page_slug', $targetSlug)
            ->orderBy('sort_order')
            ->get();

        // Group by section, preserving insertion order
        $sections = [];
        $seenFieldNames = [];
        foreach ($fields as $field) {
            if (isset($seenFieldNames[$field->name])) {
                continue; // Prevent any duplicate field names within the same form
            }
            $seenFieldNames[$field->name] = true;

            $sections[$field->section][] = [
                'id' => $field->id,
                'label' => $field->label,
                'name' => $field->name,
                'type' => $field->type,
                'options' => $field->options,
                'config' => $field->config,
                'is_required' => $field->is_required,
                'placeholder' => $field->placeholder,
                'grid_cols' => $field->grid_cols,
            ];
        }

        // Get page settings (contact email + section order)
        $pageSetting = FormPageSetting::where('page_slug', $targetSlug)->first()
            ?? FormPageSetting::whereIn('page_slug', $equivalentSlugs)->first();
        $contactEmail = $pageSetting->contact_email ?? 'info@mecarvi.com';
        $sectionOrder = $pageSetting->section_order ?? [];

        // Sort sections by stored order; unordered sections go to the end
        $sectionNames = array_keys($sections);
        usort($sectionNames, function ($a, $b) use ($sectionOrder) {
            $posA = array_search($a, $sectionOrder);
            $posB = array_search($b, $sectionOrder);
            if ($posA === false) $posA = PHP_INT_MAX;
            if ($posB === false) $posB = PHP_INT_MAX;
            return $posA - $posB;
        });

        // Convert to ordered array of section objects
        $result = [];
        foreach ($sectionNames as $sectionName) {
            $result[] = [
                'section' => $sectionName,
                'fields' => $sections[$sectionName],
            ];
        }

        $formTitle = $pageSetting->form_title ?? null;
        $formDescription = $pageSetting->form_description ?? null;
        $submitButtonText = $pageSetting->submit_button_text ?? null;

        return response()->json([
            'success' => true,
            'data' => $result,
            'contact_email' => $contactEmail,
            'form_title' => $formTitle,
            'form_description' => $formDescription,
            'submit_button_text' => $submitButtonText,
        ]);
    }

    /**
     * Admin: List all form fields.
     */
    public function index()
    {
        $pageSlug = request('page');
        $equivalentSlugs = $pageSlug ? $this->getEquivalentSlugs($pageSlug) : null;

        $fields = QuoteFormField::when($equivalentSlugs, function ($query) use ($equivalentSlugs) {
                $query->whereIn('page_slug', $equivalentSlugs);
            })
            ->orderBy('page_slug')
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $fields,
        ]);
    }

    /**
     * Admin: Create a new form field.
     */
    public function store(Request $request)
    {
        try {
            $pageSlug = $request->input('page_slug', 'quote') ?: 'quote';

            $validated = $request->validate([
                'page_slug' => 'nullable|string|max:255',
                'section' => 'required|string|max:255',
                'label' => 'required|string|max:255',
                'name' => [
                    'required', 'string', 'max:255',
                    Rule::unique('quote_form_fields', 'name')->where('page_slug', $pageSlug),
                ],
                'type' => 'required|string|in:text,email,number,tel,select,radio,checkbox,textarea,file,image,image_upload,date,quantity,paragraph,card_select,image_choice,signature,captcha,repeater,button_group,switch',
                'options' => 'nullable|array',
                'options.*' => 'nullable',
                'config' => 'nullable|array',
                'is_required' => 'boolean',
                'is_active' => 'boolean',
                'placeholder' => 'nullable|string|max:255',
                'grid_cols' => 'integer|in:1,2',
            ]);

            if (empty($validated['page_slug'])) {
                $validated['page_slug'] = 'quote';
            }

            // Auto-assign sort_order at end of section
            if (!$request->has('sort_order')) {
                $maxOrder = QuoteFormField::where('page_slug', $validated['page_slug'])
                    ->where('section', $validated['section'])
                    ->max('sort_order');
                $validated['sort_order'] = ($maxOrder ?? 0) + 1;
            } else {
                $validated['sort_order'] = $request->input('sort_order');
            }

            $field = QuoteFormField::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Form field created successfully',
                'data' => $field,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create form field',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Update an existing form field.
     */
    public function update(Request $request, $id)
    {
        try {
            $field = QuoteFormField::findOrFail($id);

            $updatePageSlug = $request->input('page_slug', $field->page_slug) ?: $field->page_slug;

            $validated = $request->validate([
                'page_slug' => 'sometimes|nullable|string|max:255',
                'section' => 'sometimes|required|string|max:255',
                'label' => 'sometimes|required|string|max:255',
                'name' => [
                    'sometimes', 'required', 'string', 'max:255',
                    Rule::unique('quote_form_fields', 'name')->where('page_slug', $updatePageSlug)->ignore($id),
                ],
                'type' => 'sometimes|required|string|in:text,email,number,tel,select,radio,checkbox,textarea,file,image,image_upload,date,quantity,paragraph,card_select,image_choice,signature,captcha,repeater,button_group,switch',
                'options' => 'nullable|array',
                'options.*' => 'nullable',
                'config' => 'nullable|array',
                'is_required' => 'boolean',
                'sort_order' => 'integer',
                'is_active' => 'boolean',
                'placeholder' => 'nullable|string|max:255',
                'grid_cols' => 'integer|in:1,2',
            ]);

            if (array_key_exists('page_slug', $validated) && empty($validated['page_slug'])) {
                $validated['page_slug'] = 'quote';
            }

            $field->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Form field updated successfully',
                'data' => $field->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update form field',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Delete a form field.
     */
    public function destroy($id)
    {
        try {
            $field = QuoteFormField::findOrFail($id);
            $field->delete();

            return response()->json([
                'success' => true,
                'message' => 'Form field deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete form field',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Bulk reorder fields.
     * Expects: { "fields": [{ "id": 1, "sort_order": 0 }, ...] }
     */
    public function reorder(Request $request)
    {
        try {
            $validated = $request->validate([
                'fields' => 'required|array',
                'fields.*.id' => 'required|integer|exists:quote_form_fields,id',
                'fields.*.sort_order' => 'required|integer',
            ]);

            foreach ($validated['fields'] as $item) {
                QuoteFormField::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fields reordered successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder fields',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Get page settings (contact email) for a page.
     */
    public function getPageSettings(Request $request)
    {
        $pageSlug = $request->query('page', 'quote');
        $setting = FormPageSetting::where('page_slug', $pageSlug)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'page_slug' => $pageSlug,
                'contact_email' => $setting->contact_email ?? 'info@mecarvi.com',
                'form_title' => $setting->form_title ?? '',
                'form_description' => $setting->form_description ?? '',
                'submit_button_text' => $setting->submit_button_text ?? '',
                'section_order' => $setting->section_order ?? [],
            ],
        ]);
    }

    /**
     * Admin: Update page settings for a page.
     */
    public function updatePageSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'page_slug' => 'required|string|max:255',
                'contact_email' => 'nullable|email|max:255',
                'form_title' => 'nullable|string|max:255',
                'form_description' => 'nullable|string|max:1000',
                'submit_button_text' => 'nullable|string|max:255',
            ]);

            $setting = FormPageSetting::updateOrCreate(
                ['page_slug' => $validated['page_slug']],
                [
                    'contact_email' => $validated['contact_email'] ?? null,
                    'form_title' => $validated['form_title'] ?? null,
                    'form_description' => $validated['form_description'] ?? null,
                    'submit_button_text' => $validated['submit_button_text'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Page settings updated successfully',
                'data' => $setting,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update page settings',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Upload a single image for image choice fields.
     */
    public function uploadImage(Request $request)
    {
        try {
            $validated = $request->validate([
                'image' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
            ]);

            $path = $request->file('image')->store('quote-form-images', 'public');

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => [
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Image upload failed',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Admin: Save section display order for a page.
     * Expects: { "page_slug": "quote", "section_order": ["Financial", "Company", ...] }
     */
    public function reorderSections(Request $request)
    {
        try {
            $validated = $request->validate([
                'page_slug' => 'required|string|max:255',
                'section_order' => 'required|array',
                'section_order.*' => 'required|string|max:255',
            ]);

            $setting = FormPageSetting::updateOrCreate(
                ['page_slug' => $validated['page_slug']],
                ['section_order' => $validated['section_order']]
            );

            return response()->json([
                'success' => true,
                'message' => 'Section order updated successfully',
                'data' => $setting,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update section order',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }
}
