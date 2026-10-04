<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCustomizationOption;
use App\Models\ProductPreviewAsset;
use App\Models\ProductPricingRule;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    protected function normalizePayload(Request $request): array
    {
        $data = $request->all();

        // Handle category resolution (no silent default; validation fails if nothing was chosen)
        if (empty($data['category_id']) || !is_numeric($data['category_id'])) {
            $data['category_id'] = null;
            $catName = trim((string) ($data['parentCategory'] ?? $data['parent_category'] ?? $data['category_name'] ?? ''));
            if ($catName !== '') {
                $parent = \App\Models\Category::whereRaw('LOWER(name) = ?', [mb_strtolower($catName)])->first()
                    ?? \App\Models\Category::create(['name' => $catName, 'slug' => Str::slug($catName), 'is_active' => true]);
                $data['category_id'] = $parent->id;

                $subName = trim((string) ($data['subCategory'] ?? $data['sub_category'] ?? ''));
                if ($subName !== '') {
                    $child = \App\Models\Category::where('parent_id', $parent->id)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])->first()
                        ?? \App\Models\Category::create([
                            'name' => $subName,
                            'slug' => Str::slug($subName) . '-' . $parent->id,
                            'parent_id' => $parent->id,
                            'is_active' => true,
                        ]);
                    $data['category_id'] = $child->id;
                }
            }
        }

        if (isset($data['sku'])) {
            $data['sku'] = trim((string) $data['sku']);
        }

        // Map camelCase fields to snake_case
        if (!isset($data['sale_price']) && isset($data['discountPrice'])) {
            $data['sale_price'] = $data['discountPrice'] !== '' ? (float) $data['discountPrice'] : null;
        }
        if (!isset($data['cost_price']) && isset($data['costPrice'])) {
            $data['cost_price'] = $data['costPrice'] !== '' ? (float) $data['costPrice'] : null;
        }
        if (!isset($data['loyalty_points_price']) && isset($data['loyaltyPoints'])) {
            $data['loyalty_points_price'] = $data['loyaltyPoints'] !== '' ? (int) $data['loyaltyPoints'] : null;
        }
        if (!isset($data['stock_quantity']) && isset($data['stockQuantity'])) {
            $data['stock_quantity'] = (int) ($data['stockQuantity'] ?: 0);
        }
        if (!isset($data['low_stock_threshold']) && isset($data['lowStockThreshold'])) {
            $data['low_stock_threshold'] = (int) ($data['lowStockThreshold'] ?: 0);
        }
        if (!isset($data['seo_title']) && isset($data['seoTitle'])) {
            $data['seo_title'] = $data['seoTitle'];
        }
        if (!isset($data['seo_description']) && isset($data['seoDescription'])) {
            $data['seo_description'] = $data['seoDescription'];
        }
        if (!isset($data['short_description']) && isset($data['shortDescription'])) {
            $data['short_description'] = $data['shortDescription'];
        }
        if (!isset($data['description']) && isset($data['fullDescription'])) {
            $data['description'] = $data['fullDescription'];
        }

        // JSON-encoded fields (multipart submissions) must be decoded before anything inspects them
        foreach (['images', 'tags', 'attributes', 'variants'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$field] = $decoded;
                }
            }
        }

        // Swap "__file:<token>" markers for uploaded option images
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $data['attributes'] = $this->resolveAttributeFiles($data['attributes'], $request);
        }
        // Dimensions: stored as a JSON string
        if (isset($data['dimensions']) && is_array($data['dimensions'])) {
            $data['dimensions'] = json_encode($data['dimensions']);
        }

        // Package customization / physical / digital details into attributes
        $extraKeys = [
            'parentCategory', 'subCategory', 'brand', 'visibility', 'keyFeatures', 'specifications',
            'refundPolicy', 'videoUrl', 'specialOffers', 'availableColors', 'availableSizes',
            'embroideryType', 'placement', 'fabricMaterial', 'threadType', 'orderOptions',
            'digitizingInstructions', 'turnaround', 'storePickup', 'delivery', 'shipping',
            'sizeStep', 'orientationStep', 'designSidesStep', 'sourceFileStep', 'turnaroundStep',
            'designInformationStep', 'serviceDetailOptions',
        ];
        $attributes = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : [];
        foreach ($extraKeys as $attrKey) {
            if (isset($data[$attrKey]) && !array_key_exists($attrKey, $attributes)) {
                $attributes[$attrKey] = $data[$attrKey];
            }
        }

        // Single source of truth for the product type
        $type = strtolower((string) ($data['product_type'] ?? $attributes['product_type'] ?? ''));
        if (!in_array($type, ['physical', 'digital', 'quotation'], true)) {
            $isDigitalFlag = filter_var($data['is_digital'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $type = $isDigitalFlag ? 'digital' : 'physical';
        }
        $attributes['product_type'] = $type;
        $data['product_type'] = $type;
        $data['is_digital'] = $type === 'digital';
        $data['attributes'] = $attributes;

        foreach (['is_active', 'is_featured'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }

        if (!isset($data['is_active']) && isset($data['status'])) {
            $data['is_active'] = strtolower($data['status']) === 'active';
        }

        return $data;
    }

    protected function resolveAttributeFiles($node, Request $request)
    {
        if (is_string($node) && str_starts_with($node, '__file:')) {
            $file = $request->file('attribute_files.' . substr($node, 7));

            return ($file && $file->isValid() && str_starts_with((string) $file->getMimeType(), 'image/'))
                ? $file->store('products/options', 'public')
                : null;
        }
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = $this->resolveAttributeFiles($v, $request);
            }
        }

        return $node;
    }
    protected function storeUploadedImages(Request $request): array
    {
        $storedImages = [];

        if ($request->hasFile('image_files')) {
            foreach (Arr::wrap($request->file('image_files')) as $file) {
                if ($file) {
                    $storedImages[] = $file->store('products', 'public');
                }
            }
        }

        return $storedImages;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::query()
            ->select([
                'id',
                'name',
                'sku',
                'description',
                'short_description',
                'price',
                'sale_price',
                'cost_price',
                'weight',
                'dimensions',
                'images',
                'tags',
                'attributes',
                'variants',
                'download_url',
                'seo_title',
                'seo_description',
                'stock_quantity',
                'low_stock_threshold',
                'category_id',
                'loyalty_points_price',
                'is_active',
                'is_featured',
                'is_digital',
                'product_type',
                'created_at',
            ])
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
            ]);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $query->where(function ($builder) use ($request) {
                $builder->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('sku', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_digital')) {
            $query->where('is_digital', $request->boolean('is_digital'));
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->get('product_type'));
        }

        if ($request->has('sort')) {
            match ($request->get('sort')) {
                'name_asc' => $query->orderBy('name', 'asc'),
                'name_desc' => $query->orderBy('name', 'desc'),
                'price_asc' => $query->orderBy('price', 'asc'),
                'price_desc' => $query->orderBy('price', 'desc'),
                'oldest' => $query->orderBy('created_at', 'asc'),
                default => $query->orderBy('created_at', 'desc'),
            };
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $products = $query->paginate(min(max((int) $request->get('per_page', 15), 1), 100));

        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $this->normalizePayload($request);

        validator($data, [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'loyalty_points_price' => 'nullable|integer|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_digital' => 'boolean',
            'product_type' => 'required|in:physical,digital,quotation',
            'download_url' => 'nullable|string',
            'seo_title' => 'nullable|string',
            'seo_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'nullable|string',
            'attributes' => 'nullable|array',
            'variants' => 'nullable|array',
        ])->validate();

        $request->validate([
            'image_files' => 'nullable|array',
            'image_files.*' => 'nullable|image|max:5120',
            'download_file' => 'nullable|file|max:102400',
        ]);

        if ($request->hasFile('download_file')) {
            $data['download_url'] = $request->file('download_file')->store('products/downloads', 'public');
        }

        $data['images'] = array_values(array_filter(array_merge(
            Arr::wrap($data['images'] ?? []),
            $this->storeUploadedImages($request)
        )));

        $product = Product::create($data);
        $this->syncProductDetailRecords($product);

        return response()->json($product->load('category'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return response()->json($product->load('category.parent'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        // Lightweight updates from catalog row actions (status / stock / featured)
        if ($request->has('_partial')) {
            $partial = $request->validate([
                'is_active' => 'sometimes|boolean',
                'is_featured' => 'sometimes|boolean',
                'stock_quantity' => 'sometimes|integer|min:0',
            ]);
            $product->update($partial);

            return response()->json($product->fresh()->load('category.parent'));
        }

        $data = $this->normalizePayload($request);

        validator($data, [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku,' . $product->id,
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'loyalty_points_price' => 'nullable|integer|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_digital' => 'boolean',
            'product_type' => 'required|in:physical,digital,quotation',
            'download_url' => 'nullable|string',
            'seo_title' => 'nullable|string',
            'seo_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'nullable|string',
            'attributes' => 'nullable|array',
            'variants' => 'nullable|array',
        ])->validate();

        $request->validate([
            'image_files' => 'nullable|array',
            'image_files.*' => 'nullable|image|max:5120',
            'download_file' => 'nullable|file|max:102400',
        ]);

        if ($request->hasFile('download_file')) {
            $data['download_url'] = $request->file('download_file')->store('products/downloads', 'public');
        }

        $data['images'] = array_values(array_filter(array_merge(
            Arr::wrap($data['images'] ?? $product->images ?? []),
            $this->storeUploadedImages($request)
        )));

        $product->update($data);
        $this->syncProductDetailRecords($product->fresh());

        return response()->json($product->load('category.parent'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        if ($product->orderItems()->exists()) {
            // Keep order history intact: hide the product instead of deleting it.
            $product->update(['is_active' => false]);
            return response()->json([
                'message' => 'Product has existing orders, so it was deactivated instead of deleted.',
                'deactivated' => true,
            ]);
        }

        $product->cartItems()->delete();
        $product->delete();
        return response()->json(['message' => 'Product deleted successfully']);
    }

    protected function syncProductDetailRecords(Product $product): void
    {
        $attributes = $product->attributes ?? [];
        $images = array_values(array_filter(Arr::wrap($product->images ?? [])));
        $sides = ['front', 'back', 'left', 'right'];

        foreach ($images as $index => $image) {
            ProductPreviewAsset::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'side' => $sides[$index] ?? 'front',
                    'sort_order' => $index,
                ],
                [
                    'image_path' => $image,
                    'is_active' => true,
                    'metadata' => ['label' => ucfirst($sides[$index] ?? 'front')],
                ]
            );
        }

        ProductPreviewAsset::where('product_id', $product->id)
            ->where('sort_order', '>=', count($images))
            ->delete();

        // Rebuild only the options this sync owns (flagged), keeping manually priced ones.
        ProductCustomizationOption::where('product_id', $product->id)
            ->where('metadata->synced', true)
            ->delete();

        if (! empty($attributes['customization_step_details']) && is_array($attributes['customization_step_details'])) {
            foreach ($attributes['customization_step_details'] as $stepItem) {
                foreach ((array) ($stepItem['fields'] ?? []) as $field) {
                    foreach ((array) ($field['options'] ?? []) as $optIndex => $optLabel) {
                        $this->upsertCustomizationOption($product, $field['key'] ?? 'custom_field', $this->labelOf($optLabel), (int) $optIndex);
                    }
                }
            }
        }

        // Wizard step objects: { enabled, options: [{ name, price, checked }] }
        $steps = [
            'embroidery_type' => $attributes['embroideryType'] ?? null,
            'placement' => $attributes['placement'] ?? null,
            'fabric_material' => $attributes['fabricMaterial'] ?? null,
            'thread_type' => $attributes['threadType'] ?? null,
            'size' => $attributes['sizeStep'] ?? null,
            'orientation' => $attributes['orientationStep'] ?? null,
            'design_sides' => $attributes['designSidesStep'] ?? null,
            'source_file' => $attributes['sourceFileStep'] ?? null,
            'turnaround' => $attributes['turnaroundStep'] ?? null,
        ];
        foreach ($steps as $type => $step) {
            if (! is_array($step) || ! array_key_exists('options', $step)) {
                continue;
            }
            if (($step['enabled'] ?? true) === false) {
                continue;
            }
            foreach (array_values((array) $step['options']) as $i => $opt) {
                if (! is_array($opt) || ($opt['checked'] ?? true) === false) {
                    continue;
                }
                $this->upsertCustomizationOption(
                    $product,
                    $type,
                    $this->labelOf($opt),
                    $i,
                    (float) preg_replace('/[^0-9.\-]/', '', (string) ($opt['price'] ?? '0')),
                    $opt['image'] ?? null,
                    $opt['colorHex'] ?? null
                );
            }
        }

        // Legacy / flat keys
        $single = [
            'embroidery_type' => $attributes['embroidery_type'] ?? null,
            'placement' => is_array($attributes['placement'] ?? null) ? null : ($attributes['placement'] ?? null),
            'size' => $attributes['size_label'] ?? null,
            'fabric_material' => $attributes['fabric_material'] ?? null,
            'thread_type' => $attributes['thread_type'] ?? null,
            'product_style' => $attributes['product_label'] ?? null,
        ];
        foreach ($single as $type => $value) {
            foreach (Arr::wrap($value) as $i => $v) {
                $this->upsertCustomizationOption($product, $type, $this->labelOf($v), (int) $i);
            }
        }
        $lists = [
            'thread_colors' => $attributes['thread_colors'] ?? null,
            'product_style' => $attributes['product_labels'] ?? null,
            'color' => $attributes['availableColors'] ?? ($attributes['color_images'] ?? null),
            'size' => $attributes['availableSizes'] ?? null,
        ];
        foreach ($lists as $type => $items) {
            foreach (Arr::wrap($items) as $i => $item) {
                $this->upsertCustomizationOption($product, $type, $this->labelOf($item), (int) $i);
            }
        }
    }

    /** Best-effort human label from a string or an option-like array. */
    protected function labelOf($item): string
    {
        if (is_array($item)) {
            foreach (['label', 'name', 'color_name', 'title', 'value'] as $k) {
                if (! empty($item[$k]) && is_scalar($item[$k])) {
                    return (string) $item[$k];
                }
            }
            return '';
        }

        return is_scalar($item) ? (string) $item : '';
    }

    protected function upsertCustomizationOption(Product $product, string $type, string $label, int $sortOrder, float $price = 0, ?string $image = null, ?string $colorHex = null): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }

        ProductCustomizationOption::updateOrCreate(
            [
                'product_id' => $product->id,
                'option_type' => $type,
                'option_key' => Str::of($label)->lower()->slug('_')->toString(),
            ],
            [
                'label' => $label,
                'price_modifier' => $price,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'metadata' => array_filter(['synced' => true, 'image' => ($image && ! str_starts_with($image, 'blob:')) ? $image : null, 'color_hex' => $colorHex]),
            ]
        );
    }
}
