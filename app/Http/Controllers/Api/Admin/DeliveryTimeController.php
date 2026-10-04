<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTime;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryTimeController extends Controller
{
    public function index()
    {
        try {
            $deliveryTimes = DeliveryTime::orderBy('priority')->get();
            $settings = SiteSetting::first();
            $deliverySettings = $settings && $settings->delivery_settings
                ? json_decode($settings->delivery_settings, true)
                : [
                    'max_delivery_radius' => 25,
                    'radius_unit' => 'miles',
                    'allow_custom_upcharges' => true,
                    'mileage_tiers' => [
                        ['id' => 'tier_1', 'min_miles' => 0, 'max_miles' => 5, 'price' => 15.00, 'label' => '0 to 5 miles'],
                        ['id' => 'tier_2', 'min_miles' => 6, 'max_miles' => 10, 'price' => 20.00, 'label' => '6 to 10 miles'],
                        ['id' => 'tier_3', 'min_miles' => 11, 'max_miles' => 15, 'price' => 25.00, 'label' => '11 to 15 miles'],
                        ['id' => 'tier_4', 'min_miles' => 16, 'max_miles' => 20, 'price' => 30.00, 'label' => '16 to 20 miles'],
                        ['id' => 'tier_5', 'min_miles' => 21, 'max_miles' => 25, 'price' => 35.00, 'label' => '21 to 25 miles'],
                    ]
                ];

            return response()->json([
                'success' => true,
                'data' => $deliveryTimes,
                'settings' => $deliverySettings,
                'delivery_settings' => $deliverySettings,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch delivery times.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'label' => 'required|string|max:255',
                'estimated_days' => 'nullable|string|max:50',
                'description' => 'nullable|string',
                'color_code' => 'required|string|max:50',
                'pricing' => 'nullable|numeric|min:0',
                'mileage_tiers' => 'nullable|array',
                'upcharge' => 'nullable|numeric|min:0',
                'priority' => 'required|integer|min:1',
                'status' => 'required|boolean',
            ]);

            if (!isset($validated['pricing'])) {
                $validated['pricing'] = isset($validated['upcharge']) ? (float)$validated['upcharge'] : 0.00;
            }
            if (!isset($validated['upcharge'])) {
                $validated['upcharge'] = isset($validated['pricing']) ? (float)$validated['pricing'] : 0.00;
            }
            if (!isset($validated['estimated_days'])) {
                $validated['estimated_days'] = 'Same Day';
            }

            $deliveryTime = DeliveryTime::create($validated);
            return response()->json(['success' => true, 'message' => 'Delivery option created successfully.', 'data' => $deliveryTime], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create delivery option.', 'error' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function show($id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            return response()->json(['success' => true, 'data' => $deliveryTime]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Delivery option not found.'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            $validated = $request->validate([
                'label' => 'sometimes|string|max:255',
                'estimated_days' => 'nullable|string|max:50',
                'description' => 'nullable|string',
                'color_code' => 'sometimes|string|max:50',
                'pricing' => 'sometimes|nullable|numeric|min:0',
                'mileage_tiers' => 'nullable|array',
                'upcharge' => 'nullable|numeric|min:0',
                'priority' => 'sometimes|integer|min:1',
                'status' => 'sometimes|boolean',
            ]);

            if (isset($validated['upcharge']) && !isset($validated['pricing'])) {
                $validated['pricing'] = (float)$validated['upcharge'];
            }
            if (isset($validated['pricing']) && !isset($validated['upcharge'])) {
                $validated['upcharge'] = (float)$validated['pricing'];
            }

            $deliveryTime->update($validated);
            return response()->json(['success' => true, 'message' => 'Delivery option updated successfully.', 'data' => $deliveryTime]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update delivery option.', 'error' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            $deliveryTime->delete();
            return response()->json(['success' => true, 'message' => 'Delivery option deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete delivery option.'], 500);
        }
    }

    public function reorder(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'required|integer|exists:delivery_times,id',
            ]);

            $ids = $request->input('ids');
            foreach ($ids as $index => $id) {
                DeliveryTime::where('id', $id)->update(['priority' => $index + 1]);
            }

            return response()->json(['success' => true, 'message' => 'Delivery options reordered successfully.']);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to reorder delivery options.'], 500);
        }
    }

    public function saveSettings(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $validated = $request->validate([
                'max_delivery_radius' => 'required|numeric|min:1',
                'radius_unit' => 'nullable|string',
                'mileage_tiers' => 'required|array|min:1',
                'mileage_tiers.*.min_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.max_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.price' => 'required|numeric|min:0',
                'mileage_tiers.*.label' => 'nullable|string',
                'allow_custom_upcharges' => 'nullable|boolean',
            ]);

            $settings->delivery_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Local delivery mileage settings saved successfully.'
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to save delivery settings.', 'error' => $e->getMessage()], 500);
        }
    }
}
