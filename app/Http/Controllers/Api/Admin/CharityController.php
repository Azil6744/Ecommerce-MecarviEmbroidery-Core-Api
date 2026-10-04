<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CharityController extends Controller
{
    /**
     * Resolve image field: handle base64 data URL or preset string / file path.
     * Returns the storage path or preset string / URL.
     */
    private function resolveImage(?string $imageString, ?string $oldPath = null, string $prefix = 'charity_'): ?string
    {
        if (!$imageString || !is_string($imageString)) {
            return null;
        }

        if (preg_match('/^data:image\/(\w+);base64,/', $imageString, $matches)) {
            $imageType = $matches[1];
            $imageData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imageString));

            if ($imageData !== false) {
                // Delete old custom image if replacing
                if ($oldPath && !in_array($oldPath, ['feeding_america', 'unicef_usa', 'red_cross', 'nature_conservancy', 'best_friends', 'helping_hands', 'st_jude', 'irc', 'generic_charity', 'mecarvi_foundation', 'green_tomorrow', 'paws_and_hope'])) {
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
                $filename = $prefix . time() . '_' . Str::random(6) . '.' . $imageType;
                $imagePath = 'charities/' . $filename;
                Storage::disk('public')->put($imagePath, $imageData);
                return $imagePath;
            }
        }

        return $imageString;
    }

    public function index(Request $request)
    {
        $charities = Charity::orderBy('created_at', 'desc')->get();
        
        $dbCompletedAmount = \App\Models\Donation::where('status', 'Completed')->sum('amount');
        $dbPendingAmount = \App\Models\Donation::where('status', 'Pending')->sum('amount');
        $totalDonationsAmount = $dbCompletedAmount + $dbPendingAmount;

        $stats = [
            'total_charities' => Charity::count(),
            'active_charities' => Charity::where('status', 'Active')->count(),
            'inactive_charities' => Charity::where('status', 'Inactive')->count(),
            'total_donations_amount' => (float) $totalDonationsAmount,
            'total_campaigns' => 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $charities,
            'stats' => $stats
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }

    /**
     * Public endpoint: returns only Active charities for the checkout page and user panel.
     */
    public function publicIndex()
    {
        $charities = Charity::where('status', 'Active')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $charities,
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }

    public function show($id)
    {
        $charity = Charity::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $charity
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'web' => 'nullable|string',
            'fax' => 'nullable|string',
            'category' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'assistance_tags' => 'nullable',
            'logo_svg_type' => 'nullable|string',
            'image' => 'nullable|string',
            'banner_image' => 'nullable|string',
            'banner_script' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'Active';
        $validated['category'] = $validated['category'] ?? 'General';
        $validated['description'] = $validated['description'] ?? ($validated['tagline'] ?? $validated['name']);
        $validated['contact_person'] = $validated['contact_person'] ?? 'Primary Contact';
        $validated['address'] = $validated['address'] ?? '';
        $validated['phone'] = $validated['phone'] ?? '';
        $validated['email'] = $validated['email'] ?? '';

        if (isset($validated['assistance_tags'])) {
            if (is_string($validated['assistance_tags'])) {
                $decoded = json_decode($validated['assistance_tags'], true);
                $validated['assistance_tags'] = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $validated['assistance_tags'])));
            } elseif (!is_array($validated['assistance_tags'])) {
                $validated['assistance_tags'] = [];
            }
        } else {
            $validated['assistance_tags'] = ['Community Development'];
        }

        // Process images
        $validated['logo_svg_type'] = $this->resolveImage($request->input('logo_svg_type') ?? $request->input('logoUrl'), null, 'logo_');
        if (!$validated['logo_svg_type']) {
            $validated['logo_svg_type'] = 'mecarvi_foundation';
        }

        if ($request->has('image')) {
            $validated['image'] = $this->resolveImage($request->input('image'), null, 'img_');
        }
        if ($request->has('banner_image')) {
            $validated['banner_image'] = $this->resolveImage($request->input('banner_image'), null, 'banner_');
        }

        $charity = Charity::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Charity created successfully',
            'data' => $charity
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function update(Request $request, $id)
    {
        $charity = Charity::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'web' => 'nullable|string',
            'fax' => 'nullable|string',
            'category' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'assistance_tags' => 'nullable',
            'logo_svg_type' => 'nullable|string',
            'image' => 'nullable|string',
            'banner_image' => 'nullable|string',
            'banner_script' => 'nullable|string',
        ]);

        if (isset($validated['assistance_tags'])) {
            if (is_string($validated['assistance_tags'])) {
                $decoded = json_decode($validated['assistance_tags'], true);
                $validated['assistance_tags'] = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $validated['assistance_tags'])));
            } elseif (!is_array($validated['assistance_tags'])) {
                $validated['assistance_tags'] = [];
            }
        }

        if ($request->has('logo_svg_type') || $request->has('logoUrl')) {
            $rawLogo = $request->input('logo_svg_type') ?? $request->input('logoUrl');
            $validated['logo_svg_type'] = $this->resolveImage($rawLogo, $charity->logo_svg_type, 'logo_');
        }

        if ($request->has('image')) {
            $validated['image'] = $this->resolveImage($request->input('image'), $charity->image, 'img_');
        }

        if ($request->has('banner_image')) {
            $validated['banner_image'] = $this->resolveImage($request->input('banner_image'), $charity->banner_image, 'banner_');
        }

        $charity->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Charity updated successfully',
            'data' => $charity
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function destroy($id)
    {
        $charity = Charity::findOrFail($id);
        
        // Clean up stored image if custom
        if ($charity->logo_svg_type && str_starts_with($charity->logo_svg_type, 'charities/')) {
            Storage::disk('public')->delete($charity->logo_svg_type);
        }
        if ($charity->image && str_starts_with($charity->image, 'charities/')) {
            Storage::disk('public')->delete($charity->image);
        }
        if ($charity->banner_image && str_starts_with($charity->banner_image, 'charities/')) {
            Storage::disk('public')->delete($charity->banner_image);
        }

        $charity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Charity deleted successfully'
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function toggleStatus($id)
    {
        $charity = Charity::findOrFail($id);
        $charity->status = $charity->status === 'Active' ? 'Inactive' : 'Active';
        $charity->save();

        return response()->json([
            'success' => true,
            'message' => 'Charity status updated successfully',
            'data' => $charity
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
