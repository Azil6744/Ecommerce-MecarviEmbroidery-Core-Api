<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductColor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductColorController extends Controller
{
    public function index()
    {
        return response()->json(
            ProductColor::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductColor $color) => $this->transformColor($color))
                ->values()
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $color = new ProductColor();
        $this->fillColor($color, $validated, $request);
        $color->save();

        return response()->json($this->transformColor($color), 201);
    }

    public function show(ProductColor $productColor)
    {
        return response()->json($this->transformColor($productColor));
    }

    public function update(Request $request, ProductColor $productColor)
    {
        $validated = $this->validatePayload($request, $productColor->id);
        $this->fillColor($productColor, $validated, $request);
        $productColor->save();

        return response()->json($this->transformColor($productColor));
    }

    public function destroy(ProductColor $productColor)
    {
        if ($productColor->swatch_image) {
            Storage::disk('public')->delete($productColor->swatch_image);
        }

        $productColor->delete();

        return response()->json(['message' => 'Product color deleted successfully']);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('product_colors', 'name')->ignore($ignoreId)],
            'hex_code' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'remove_swatch_image' => ['nullable', 'boolean'],
            'swatch_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    private function fillColor(ProductColor $color, array $validated, Request $request): void
    {
        $hex = ! empty($validated['hex_code']) ? strtoupper($validated['hex_code']) : null;
        $removeSwatch = (bool) ($validated['remove_swatch_image'] ?? false);
        $willHaveSwatch = $request->hasFile('swatch_image') || ($color->swatch_image && ! $removeSwatch);

        // A color needs at least one visual: a hex code or a swatch image (either is fine).
        if (! $hex && ! $willHaveSwatch) {
            throw ValidationException::withMessages([
                'hex_code' => ['Enter a hex color code or upload a color image.'],
            ]);
        }

        $color->name = trim($validated['name']);
        $color->slug = Str::slug($validated['name']);
        $color->hex_code = $hex;
        $color->sort_order = $validated['sort_order'] ?? ($color->sort_order ?: 1);
        $color->is_active = (bool) ($validated['is_active'] ?? $color->is_active ?? true);

        if ($removeSwatch && $color->swatch_image) {
            Storage::disk('public')->delete($color->swatch_image);
            $color->swatch_image = null;
        }

        if ($request->hasFile('swatch_image')) {
            if ($color->swatch_image) {
                Storage::disk('public')->delete($color->swatch_image);
            }

            $color->swatch_image = $request->file('swatch_image')->store('product-colors', 'public');
        }
    }

    private function transformColor(ProductColor $color): array
    {
        return [
            'id' => $color->id,
            'name' => $color->name,
            'slug' => $color->slug,
            'hex_code' => $color->hex_code ? strtoupper($color->hex_code) : null,
            'swatch_image' => $color->swatch_image,
            'swatch_image_url' => $color->swatch_image ? Storage::disk('public')->url($color->swatch_image) : null,
            'sort_order' => (int) $color->sort_order,
            'is_active' => (bool) $color->is_active,
            'status' => $color->is_active ? 'Active' : 'Inactive',
            'created_at' => optional($color->created_at)->toISOString(),
            'updated_at' => optional($color->updated_at)->toISOString(),
        ];
    }
}
