<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryTimeController extends Controller
{
    public function index()
    {
        try {
            $deliveryTimes = DeliveryTime::orderBy('priority')->get();
            return response()->json(['success' => true, 'data' => $deliveryTimes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch delivery times.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'label' => 'required|string|max:255',
                'estimated_days' => 'required|string|max:50',
                'description' => 'nullable|string',
                'color_code' => 'required|string|max:50',
                'pricing' => 'required|numeric|min:0',
                'priority' => 'required|integer|min:1',
                'status' => 'required|boolean',
                'availability' => 'nullable|string|max:100',
                'cutoff_time' => 'nullable|string|max:50',
                'is_default' => 'nullable|boolean',
                'icon' => 'nullable|string|max:50',
            ]);

            if (!empty($validated['is_default'])) {
                DeliveryTime::where('id', '>', 0)->update(['is_default' => false]);
            }

            $deliveryTime = DeliveryTime::create($validated);
            return response()->json(['success' => true, 'message' => 'Delivery time created successfully.', 'data' => $deliveryTime], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create delivery time.', 'error' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function show($id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            return response()->json(['success' => true, 'data' => $deliveryTime]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Delivery time not found.'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            $validated = $request->validate([
                'label' => 'sometimes|string|max:255',
                'estimated_days' => 'sometimes|string|max:50',
                'description' => 'nullable|string',
                'color_code' => 'sometimes|string|max:50',
                'pricing' => 'sometimes|numeric|min:0',
                'priority' => 'sometimes|integer|min:1',
                'status' => 'sometimes|boolean',
                'availability' => 'nullable|string|max:100',
                'cutoff_time' => 'nullable|string|max:50',
                'is_default' => 'nullable|boolean',
                'icon' => 'nullable|string|max:50',
            ]);

            if (isset($validated['is_default']) && $validated['is_default']) {
                DeliveryTime::where('id', '!=', $id)->update(['is_default' => false]);
            }

            $deliveryTime->update($validated);
            return response()->json(['success' => true, 'message' => 'Delivery time updated successfully.', 'data' => $deliveryTime]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update delivery time.', 'error' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $deliveryTime = DeliveryTime::findOrFail($id);
            $deliveryTime->delete();
            return response()->json(['success' => true, 'message' => 'Delivery time deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete delivery time.'], 500);
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

            return response()->json(['success' => true, 'message' => 'Delivery times reordered successfully.']);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to reorder delivery times.'], 500);
        }
    }
}
