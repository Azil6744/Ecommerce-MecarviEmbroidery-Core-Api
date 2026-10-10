<?php

namespace App\Http\Controllers\Api\Admin\faqpage;

use App\Http\Controllers\Controller;
use App\Models\FAQItem;
use App\Models\FAQCategory;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FAQItemController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get all FAQ items.
     * 
     * Returns all FAQ items ordered by order field. Can filter by faq_category_id.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = FAQItem::with('category')->orderBy('order', 'asc');
        
        // Filter by category if provided
        if ($request->has('faq_category_id')) {
            $query->where('faq_category_id', $request->input('faq_category_id'));
        }
        
        $items = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'faq_items' => $items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'faq_category_id' => $item->faq_category_id,
                        'category_name' => $item->category ? $item->category->category_name : null,
                        'question' => $item->question,
                        'answer' => $item->answer,
                        'icon' => $item->icon,
                        'order' => $item->order,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ];
                }),
            ],
        ], 200);
    }

    /**
     * Get a specific FAQ item by ID.
     * 
     * Returns a single FAQ item configuration.
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $item = FAQItem::with('category')->find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ item not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'faq_item' => [
                    'id' => $item->id,
                    'faq_category_id' => $item->faq_category_id,
                    'category_name' => $item->category ? $item->category->category_name : null,
                    'question' => $item->question,
                    'answer' => $item->answer,
                    'icon' => $item->icon,
                    'order' => $item->order,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ],
            ],
        ], 200);
    }

    /**
     * Create a new FAQ item.
     * 
     * Creates a new FAQ item. Category must exist first.
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
                    'message' => 'Unauthorized. Only admins can create FAQ items.',
                ], 403);
            }

            $validated = $request->validate([
                'faq_category_id' => ['required', 'exists:faq_categories,id'],
                'question' => ['required', 'string', 'max:255'],
                'answer' => ['required', 'string'],
                'icon' => ['nullable', 'string', 'max:255'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]);

            // Set default order if not provided (within the category)
            if (!isset($validated['order'])) {
                $maxOrder = FAQItem::where('faq_category_id', $validated['faq_category_id'])
                    ->max('order') ?? 0;
                $validated['order'] = $maxOrder + 1;
            }

            $item = FAQItem::with('category')->create($validated);
            $item->refresh();
            $item->load('category');

            $this->broadcastContentUpdate('faq-item', 'created', [
                'id' => $item->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ item created successfully',
                'data' => [
                    'faq_item' => [
                        'id' => $item->id,
                        'faq_category_id' => $item->faq_category_id,
                        'category_name' => $item->category ? $item->category->category_name : null,
                        'question' => $item->question,
                        'answer' => $item->answer,
                        'icon' => $item->icon,
                        'order' => $item->order,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
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
                'message' => 'FAQ item creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ item creation.',
            ], 500);
        }
    }

    /**
     * Update FAQ item content.
     * 
     * Updates the existing FAQ item configuration.
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
                    'message' => 'Unauthorized. Only admins can update FAQ items.',
                ], 403);
            }

            $item = FAQItem::with('category')->find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ item not found.',
                ], 404);
            }

            $dataToUpdate = [];

            if ($request->has('faq_category_id')) {
                $dataToUpdate['faq_category_id'] = $request->input('faq_category_id');
            }
            if ($request->has('question')) {
                $dataToUpdate['question'] = $request->input('question');
            }
            if ($request->has('answer')) {
                $dataToUpdate['answer'] = $request->input('answer');
            }
            if ($request->has('icon')) {
                $dataToUpdate['icon'] = $request->input('icon');
            }
            if ($request->has('order')) {
                $dataToUpdate['order'] = $request->input('order');
            }

            if (!empty($dataToUpdate)) {
                $rules = [];
                
                if (isset($dataToUpdate['faq_category_id'])) {
                    $rules['faq_category_id'] = ['required', 'exists:faq_categories,id'];
                }
                if (isset($dataToUpdate['question'])) {
                    $rules['question'] = ['required', 'string', 'max:255'];
                }
                if (isset($dataToUpdate['answer'])) {
                    $rules['answer'] = ['required', 'string'];
                }
                if (isset($dataToUpdate['icon'])) {
                    $rules['icon'] = ['nullable', 'string', 'max:255'];
                }
                if (isset($dataToUpdate['order'])) {
                    $rules['order'] = ['nullable', 'integer', 'min:0'];
                }

                if (!empty($rules)) {
                    $request->validate($rules);
                }

                $item->fill($dataToUpdate);
                $item->save();
                $item->refresh();
                $item->load('category');

                $this->broadcastContentUpdate('faq-item', 'updated', [
                    'id' => $item->id,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided to update.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'FAQ item updated successfully',
                'data' => [
                    'faq_item' => [
                        'id' => $item->id,
                        'faq_category_id' => $item->faq_category_id,
                        'category_name' => $item->category ? $item->category->category_name : null,
                        'question' => $item->question,
                        'answer' => $item->answer,
                        'icon' => $item->icon,
                        'order' => $item->order,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
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
                'message' => 'FAQ item update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ item update.',
            ], 500);
        }
    }

    /**
     * Delete FAQ item content.
     * 
     * Deletes the specified FAQ item.
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
                    'message' => 'Unauthorized. Only admins can delete FAQ items.',
                ], 403);
            }

            $item = FAQItem::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'FAQ item not found.',
                ], 404);
            }

            $itemId = $item->id;
            $item->delete();

            $this->broadcastContentUpdate('faq-item', 'deleted', [
                'id' => $itemId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ item deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ item deletion failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during FAQ item deletion.',
            ], 500);
        }
    }
}
