<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalAttribute;
use App\Models\GlobalAttributeValue;
use App\Models\Product;
use App\Http\Controllers\Api\Admin\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminAttributeController extends Controller
{
    public function index(Request $request)
    {
        $query = GlobalAttribute::with('values');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $attributes = $query->orderBy('sort_order')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $attributes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'pricing_mode' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            'values' => ['nullable', 'array'],
            'values.*.name' => ['required', 'string', 'max:255'],
            'values.*.image_path' => ['nullable', 'string', 'max:255'],
            'values.*.description' => ['nullable', 'string'],
            'values.*.price' => ['required', 'numeric', 'min:0'],
            'values.*.pricing_mode' => ['required', 'string'],
            'values.*.status' => ['required', 'string'],
            'values.*.sort_order' => ['nullable', 'integer'],
        ]);

        $attribute = DB::transaction(function () use ($validated) {
            $attribute = GlobalAttribute::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'pricing_mode' => $validated['pricing_mode'],
                'status' => $validated['status'],
                'sort_order' => 0,
            ]);

            if (!empty($validated['values'])) {
                foreach ($validated['values'] as $index => $val) {
                    $attribute->values()->create([
                        'name' => $val['name'],
                        'image_path' => $val['image_path'] ?? null,
                        'description' => $val['description'] ?? null,
                        'price' => $val['price'],
                        'pricing_mode' => $val['pricing_mode'],
                        'status' => $val['status'],
                        'sort_order' => $val['sort_order'] ?? $index,
                    ]);
                }
            }

            return $attribute;
        });

        return response()->json([
            'success' => true,
            'message' => 'Attribute created successfully.',
            'data' => $attribute->load('values'),
        ], 201);
    }

    public function show(GlobalAttribute $attribute)
    {
        return response()->json([
            'success' => true,
            'data' => $attribute->load('values'),
        ]);
    }

    public function update(Request $request, GlobalAttribute $attribute)
    {
        $oldName = $attribute->name;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'pricing_mode' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            'values' => ['nullable', 'array'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.name' => ['required', 'string', 'max:255'],
            'values.*.image_path' => ['nullable', 'string', 'max:255'],
            'values.*.description' => ['nullable', 'string'],
            'values.*.price' => ['required', 'numeric', 'min:0'],
            'values.*.pricing_mode' => ['required', 'string'],
            'values.*.status' => ['required', 'string'],
            'values.*.sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($validated, $attribute) {
            $attribute->update([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'pricing_mode' => $validated['pricing_mode'],
                'status' => $validated['status'],
            ]);

            $incomingIds = [];
            if (!empty($validated['values'])) {
                foreach ($validated['values'] as $index => $val) {
                    if (!empty($val['id'])) {
                        // Update existing value
                        $attributeValue = GlobalAttributeValue::findOrFail($val['id']);
                        $attributeValue->update([
                            'name' => $val['name'],
                            'image_path' => $val['image_path'] ?? null,
                            'description' => $val['description'] ?? null,
                            'price' => $val['price'],
                            'pricing_mode' => $val['pricing_mode'],
                            'status' => $val['status'],
                            'sort_order' => $val['sort_order'] ?? $index,
                        ]);
                        $incomingIds[] = $val['id'];
                    } else {
                        // Create new value
                        $newVal = $attribute->values()->create([
                            'name' => $val['name'],
                            'image_path' => $val['image_path'] ?? null,
                            'description' => $val['description'] ?? null,
                            'price' => $val['price'],
                            'pricing_mode' => $val['pricing_mode'],
                            'status' => $val['status'],
                            'sort_order' => $val['sort_order'] ?? $index,
                        ]);
                        $incomingIds[] = $newVal->id;
                    }
                }
            }

            // Delete values not present in incoming list
            $attribute->values()->whereNotIn('id', $incomingIds)->delete();
        });

        $freshAttribute = $attribute->fresh()->load('values');

        try {
            $this->propagateAttributeToProducts($freshAttribute, $oldName);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to propagate attribute updates to products: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Attribute updated successfully.',
            'data' => $freshAttribute,
        ]);
    }

    public function destroy(GlobalAttribute $attribute)
    {
        $attribute->delete();

        return response()->json([
            'success' => true,
            'message' => 'Attribute deleted successfully.',
        ]);
    }

    /**
     * Propagate master attribute updates to all products that reference this attribute.
     */
    protected function propagateAttributeToProducts(GlobalAttribute $attribute, ?string $oldName = null): void
    {
        $attribute->load('values');
        $masterValues = $attribute->values;
        $masterValuesMap = [];
        foreach ($masterValues as $val) {
            $valName = mb_strtolower(trim((string)$val->name));
            $masterValuesMap[$valName] = $val;
            $cleanName = preg_replace('/[^a-z0-9]/', '', $valName);
            if ($cleanName !== '') {
                $masterValuesMap[$cleanName] = $val;
            }
        }

        $targetNames = array_unique(array_filter([
            mb_strtolower(trim((string)$attribute->name)),
            $oldName ? mb_strtolower(trim((string)$oldName)) : null,
            preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$attribute->name))),
            $oldName ? preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$oldName))) : null,
        ]));

        $productController = null;
        try {
            $productController = app(ProductController::class);
        } catch (\Throwable $e) {}

        // Cursor across products
        Product::query()->cursor()->each(function ($product) use ($attribute, $targetNames, $masterValues, $masterValuesMap, $productController) {
            $attributes = $product->attributes;
            if (!is_array($attributes) || empty($attributes)) {
                return;
            }

            $modified = false;

            $stepKeys = [
                'embroideryType', 'placement', 'fabricMaterial', 'threadType',
                'orderOptions', 'digitizingInstructions',
                'sizeStep', 'orientationStep', 'designSidesStep', 'sourceFileStep', 'turnaroundStep',
            ];

            foreach ($stepKeys as $key) {
                if (!isset($attributes[$key]) || !is_array($attributes[$key])) {
                    continue;
                }
                $step = &$attributes[$key];
                $currentAttr = mb_strtolower(trim((string)($step['productAttribute'] ?? '')));
                $cleanCurrentAttr = preg_replace('/[^a-z0-9]/', '', $currentAttr);

                if (in_array($currentAttr, $targetNames, true) || in_array($cleanCurrentAttr, $targetNames, true)) {
                    if (($step['productAttribute'] ?? '') !== $attribute->name) {
                        $step['productAttribute'] = $attribute->name;
                        $modified = true;
                    }

                    $options = is_array($step['options'] ?? null) ? $step['options'] : [];
                    $existingNames = [];

                    foreach ($options as &$opt) {
                        if (!is_array($opt)) continue;
                        $optName = mb_strtolower(trim((string)($opt['name'] ?? '')));
                        $cleanOptName = preg_replace('/[^a-z0-9]/', '', $optName);
                        $existingNames[$optName] = true;
                        if ($cleanOptName !== '') {
                            $existingNames[$cleanOptName] = true;
                        }

                        $mVal = $masterValuesMap[$optName] ?? ($masterValuesMap[$cleanOptName] ?? null);
                        if ($mVal) {
                            $mPrice = number_format((float)$mVal->price, 2, '.', '');
                            if ((string)($opt['price'] ?? '') !== $mPrice) {
                                $opt['price'] = $mPrice;
                                $modified = true;
                            }
                            if (!empty($mVal->description) && ($opt['description'] ?? '') !== $mVal->description) {
                                $opt['description'] = $mVal->description;
                                $modified = true;
                            }
                            if (!empty($mVal->image_path)) {
                                $imgUrl = Storage::disk('public')->url($mVal->image_path);
                                if (($opt['image'] ?? '') !== $imgUrl) {
                                    $opt['image'] = $imgUrl;
                                    $modified = true;
                                }
                            }
                        }
                    }
                    unset($opt);

                    // Append any new values from master
                    foreach ($masterValues as $mVal) {
                        $mName = mb_strtolower(trim((string)$mVal->name));
                        $cleanMName = preg_replace('/[^a-z0-9]/', '', $mName);
                        if (!isset($existingNames[$mName]) && !isset($existingNames[$cleanMName])) {
                            $options[] = [
                                'id' => 'opt_db_' . $mVal->id . '_' . time(),
                                'name' => $mVal->name,
                                'description' => $mVal->description,
                                'price' => number_format((float)$mVal->price, 2, '.', ''),
                                'checked' => true,
                                'image' => $mVal->image_path ? Storage::disk('public')->url($mVal->image_path) : null,
                            ];
                            $modified = true;
                        }
                    }
                    $step['options'] = $options;
                }
                unset($step);
            }

            // Check customAttributes
            if (isset($attributes['customAttributes']) && is_array($attributes['customAttributes'])) {
                foreach ($attributes['customAttributes'] as &$cStep) {
                    if (!is_array($cStep)) continue;
                    $currentAttr = mb_strtolower(trim((string)($cStep['productAttribute'] ?? $cStep['stepName'] ?? '')));
                    $cleanCurrentAttr = preg_replace('/[^a-z0-9]/', '', $currentAttr);

                    if (in_array($currentAttr, $targetNames, true) || in_array($cleanCurrentAttr, $targetNames, true)) {
                        if (($cStep['productAttribute'] ?? '') !== $attribute->name) {
                            $cStep['productAttribute'] = $attribute->name;
                            $modified = true;
                        }

                        $options = is_array($cStep['options'] ?? null) ? $cStep['options'] : [];
                        $existingNames = [];

                        foreach ($options as &$opt) {
                            if (!is_array($opt)) continue;
                            $optName = mb_strtolower(trim((string)($opt['name'] ?? '')));
                            $cleanOptName = preg_replace('/[^a-z0-9]/', '', $optName);
                            $existingNames[$optName] = true;
                            if ($cleanOptName !== '') {
                                $existingNames[$cleanOptName] = true;
                            }

                            $mVal = $masterValuesMap[$optName] ?? ($masterValuesMap[$cleanOptName] ?? null);
                            if ($mVal) {
                                $mPrice = number_format((float)$mVal->price, 2, '.', '');
                                if ((string)($opt['price'] ?? '') !== $mPrice) {
                                    $opt['price'] = $mPrice;
                                    $modified = true;
                                }
                                if (!empty($mVal->description) && ($opt['description'] ?? '') !== $mVal->description) {
                                    $opt['description'] = $mVal->description;
                                    $modified = true;
                                }
                                if (!empty($mVal->image_path)) {
                                    $imgUrl = Storage::disk('public')->url($mVal->image_path);
                                    if (($opt['image'] ?? '') !== $imgUrl) {
                                        $opt['image'] = $imgUrl;
                                        $modified = true;
                                    }
                                }
                            }
                        }
                        unset($opt);

                        foreach ($masterValues as $mVal) {
                            $mName = mb_strtolower(trim((string)$mVal->name));
                            $cleanMName = preg_replace('/[^a-z0-9]/', '', $mName);
                            if (!isset($existingNames[$mName]) && !isset($existingNames[$cleanMName])) {
                                $options[] = [
                                    'id' => 'opt_db_' . $mVal->id . '_' . time(),
                                    'name' => $mVal->name,
                                    'description' => $mVal->description,
                                    'price' => number_format((float)$mVal->price, 2, '.', ''),
                                    'checked' => true,
                                    'image' => $mVal->image_path ? Storage::disk('public')->url($mVal->image_path) : null,
                                ];
                                $modified = true;
                            }
                        }
                        $cStep['options'] = $options;
                    }
                }
                unset($cStep);
            }

            // Check extraCatalogAttributes
            if (isset($attributes['extraCatalogAttributes']) && is_array($attributes['extraCatalogAttributes'])) {
                foreach ($attributes['extraCatalogAttributes'] as &$extra) {
                    if (!is_array($extra)) continue;
                    $currentAttr = mb_strtolower(trim((string)($extra['attribute'] ?? '')));
                    $cleanCurrentAttr = preg_replace('/[^a-z0-9]/', '', $currentAttr);

                    if (in_array($currentAttr, $targetNames, true) || in_array($cleanCurrentAttr, $targetNames, true)) {
                        if (($extra['attribute'] ?? '') !== $attribute->name) {
                            $extra['attribute'] = $attribute->name;
                            $modified = true;
                        }
                    }
                }
                unset($extra);
            }

            if ($modified) {
                $product->attributes = $attributes;
                $product->save();

                if ($productController) {
                    try {
                        $productController->syncCustomizationOptions($product);
                    } catch (\Throwable $e) {}
                }
            }
        });
    }

    public function uploadImage(Request $request)
    {
        try {
            $request->validate([
                'image' => ['required', 'file', 'mimes:jpeg,jpg,png,gif,webp,svg,bmp,avif', 'max:10240'],
            ]);

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('attribute-values', 'public');
                $url = Storage::disk('public')->url($path);

                return response()->json([
                    'success' => true,
                    'message' => 'Image uploaded successfully.',
                    'image_path' => $path,
                    'url' => $url,
                    'data' => [
                        'path' => $path,
                        'image_path' => $path,
                        'url' => $url,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No image file was provided in the request.',
            ], 400);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

