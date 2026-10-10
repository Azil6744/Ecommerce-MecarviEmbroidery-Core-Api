<?php

namespace App\Http\Controllers\Api\Admin\faqpage;

use App\Http\Controllers\Controller;
use App\Models\FAQCategory;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FAQCategoryController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get all FAQ categories.
     * 
     * Returns all FAQ categories ordered by order field.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $categories = FAQCategory::withCount('faqItems')->orderBy('order', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'faq_categories' => $categories->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'category_name' => $category->category_name,
                        'description' => $category->description,
                        'icon' => $category->icon,
                        'image' => $category->image,
                        'order' => $category->order,
                        'faq_items_count' => $category->faq_items_count,
                        'created_at' => $category->created_at,
                        'updated_at' => $category->updated_at,
                    ];
                }),
            ],
        ], 200);
    }

    /**
     * Get a specific FAQ category by ID.
     * 
     * Returns a single FAQ category with its FAQ items.
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $category = FAQCategory::with(['faqItems' => function($query) {
            $query->orderBy('order', 'asc');
        }])->find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ category not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'faq_category' => [
                    'id' => $category->id,
                    'category_name' => $category->category_name,
                    'description' => $category->description,
                    'icon' => $category->icon,
                    'image' => $category->image,
                    'order' => $category->order,
                    'faq_items' => $category->faqItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'faq_category_id' => $item->faq_category_id,
                            'question' => $item->question,
                            'answer' => $item->answer,
                            'icon' => $item->icon,
                            'order' => $item->order,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ],
            ],
        ], 200);
    }

    /**
     * Create a new FAQ category.
     * 
     * Creates a new FAQ category.
     * Only super admin and editor can access this.
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
                    'message' => 'Unauthorized. Only admins can create FAQ categories.',
                ], 403);
            }

            $validated = $request->validate([
                'category_name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'icon' => ['nullable', 'string', 'max:255'],
                'image' => ['nullable', 'string', 'max:1000'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]);

            // Set default order if not provided
            if (!isset($validated['order'])) {
                $maxOrder = FAQCategory::max('order') ?? 0;
                $validated['order'] = $maxOrder + 1;
            }

            $category = FAQCategory::create($validated);

            $this->broadcastContentUpdate('faq-category', 'created', [
                'id' => $category->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ category created successfully',
                'data' => [
                    'faq_category' => [
                        'id' => $category->id,
                        'category_name' => $category->category_name,
                        'description' => $category->description,
                        'icon' => $category->icon,
                        'image' => $category->image,
                        'order' => $category->order,
                        'created_at' => $category->created_at,
                        'updated_at' => $category->updated_at,
                    ],
                ],
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
                'message' => 'FAQ category creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ category creation.',
            ], 500);
        }
    }

    /**
     * Update FAQ category content.
     * 
     * Updates the existing FAQ category configuration.
     * Only super admin and editor can access this.
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
                    'message' => 'Unauthorized. Only admins can update FAQ categories.',
                ], 403);
            }

            $category = FAQCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ category not found.',
                ], 404);
            }

            $dataToUpdate = [];

            if ($request->has('category_name')) {
                $dataToUpdate['category_name'] = $request->input('category_name');
            }
            if ($request->has('description')) {
                $dataToUpdate['description'] = $request->input('description');
            }
            if ($request->has('icon')) {
                $dataToUpdate['icon'] = $request->input('icon');
            }
            if ($request->has('image')) {
                $dataToUpdate['image'] = $request->input('image');
            }
            if ($request->has('order')) {
                $dataToUpdate['order'] = $request->input('order');
            }

            if (!empty($dataToUpdate)) {
                $rules = [];
                
                if (isset($dataToUpdate['category_name'])) {
                    $rules['category_name'] = ['required', 'string', 'max:255'];
                }
                if (isset($dataToUpdate['description'])) {
                    $rules['description'] = ['nullable', 'string'];
                }
                if (isset($dataToUpdate['icon'])) {
                    $rules['icon'] = ['nullable', 'string', 'max:255'];
                }
                if (isset($dataToUpdate['image'])) {
                    $rules['image'] = ['nullable', 'string', 'max:1000'];
                }
                if (isset($dataToUpdate['order'])) {
                    $rules['order'] = ['nullable', 'integer', 'min:0'];
                }

                if (!empty($rules)) {
                    $request->validate($rules);
                }

                $category->fill($dataToUpdate);
                $category->save();
                $category->refresh();

                $this->broadcastContentUpdate('faq-category', 'updated', [
                    'id' => $category->id,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided to update.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAQ category updated successfully',
                'data' => [
                    'faq_category' => [
                        'id' => $category->id,
                        'category_name' => $category->category_name,
                        'description' => $category->description,
                        'icon' => $category->icon,
                        'image' => $category->image,
                        'order' => $category->order,
                        'created_at' => $category->created_at,
                        'updated_at' => $category->updated_at,
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
                'message' => 'FAQ category update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ category update.',
            ], 500);
        }
    }

    /**
     * Delete FAQ category content.
     * 
     * Deletes the specified FAQ category and all associated FAQ items.
     * Only super admin and editor can access this.
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
                    'message' => 'Unauthorized. Only admins can delete FAQ categories.',
                ], 403);
            }

            $category = FAQCategory::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ category not found.',
                ], 404);
            }

            $categoryId = $category->id;
            $category->delete();

            $this->broadcastContentUpdate('faq-category', 'deleted', [
                'id' => $categoryId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ category deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ category deletion failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ category deletion.',
            ], 500);
        }
    }
}
