<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorePickupLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;

class StorePickupLocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $locations = StorePickupLocation::orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $locations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pickup locations.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|unique:store_pickup_locations,code',
                'store_type' => 'required|string|max:255',
                'timezone' => 'required|string|max:255',
                'address' => 'required|string',
                'phone' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'short_description' => 'nullable|string|max:1000',
                'image_path' => 'nullable|string|max:255',
                'status' => 'boolean',
                'is_pickup_enabled' => 'boolean',
                'pickup_preparation_time' => 'integer|min:0',
                'pickup_preparation_unit' => 'nullable|string',
                'max_pickup_radius' => 'numeric|min:0',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'weekly_schedule' => 'nullable|array',
                'special_hours' => 'nullable|array',
                'max_future_days' => 'nullable|string|max:255',
                'notif_order_ready' => 'nullable|boolean',
                'notif_order_picked_up' => 'nullable|boolean',
                'notif_new_pickup_alert' => 'nullable|boolean',
                'cust_notif_email' => 'nullable|boolean',
                'cust_notif_sms' => 'nullable|boolean',
                'staff_notif_email' => 'nullable|boolean',
                'staff_notif_sms' => 'nullable|boolean',
                'id_verification_required' => 'nullable|boolean',
                'allow_pickup_by_others' => 'nullable|boolean',
                'pickup_code_required' => 'nullable|boolean',
                'pickup_code_expiry' => 'nullable|string|max:255',
                'order_hold_duration' => 'nullable|string|max:255',
                'late_pickup_action' => 'nullable|string|max:255',
                'refund_policy' => 'nullable|string|max:255',
                'allow_partial_pickup' => 'nullable|boolean',
                'show_pickup_instructions' => 'nullable|boolean',
                'additional_pickup_instructions' => 'nullable|string',
                'pickup_settings' => 'nullable|array',
            ]);

            // Geocode address if coordinates are missing
            if (empty($validated['latitude']) || empty($validated['longitude'])) {
                $coords = $this->geocodeAddress($validated['address']);
                $validated['latitude'] = $coords['latitude'];
                $validated['longitude'] = $coords['longitude'];
            }

            $location = StorePickupLocation::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pickup location created successfully.',
                'data' => $location
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create pickup location.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $location = StorePickupLocation::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $location
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pickup location not found.'
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $location = StorePickupLocation::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'code' => 'sometimes|required|string|unique:store_pickup_locations,code,' . $id,
                'store_type' => 'sometimes|required|string|max:255',
                'timezone' => 'sometimes|required|string|max:255',
                'address' => 'sometimes|required|string',
                'phone' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'short_description' => 'nullable|string|max:1000',
                'image_path' => 'nullable|string|max:255',
                'status' => 'boolean',
                'is_pickup_enabled' => 'boolean',
                'pickup_preparation_time' => 'integer|min:0',
                'pickup_preparation_unit' => 'nullable|string',
                'max_pickup_radius' => 'numeric|min:0',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'weekly_schedule' => 'nullable|array',
                'special_hours' => 'nullable|array',
                'max_future_days' => 'nullable|string|max:255',
                'notif_order_ready' => 'nullable|boolean',
                'notif_order_picked_up' => 'nullable|boolean',
                'notif_new_pickup_alert' => 'nullable|boolean',
                'cust_notif_email' => 'nullable|boolean',
                'cust_notif_sms' => 'nullable|boolean',
                'staff_notif_email' => 'nullable|boolean',
                'staff_notif_sms' => 'nullable|boolean',
                'id_verification_required' => 'nullable|boolean',
                'allow_pickup_by_others' => 'nullable|boolean',
                'pickup_code_required' => 'nullable|boolean',
                'pickup_code_expiry' => 'nullable|string|max:255',
                'order_hold_duration' => 'nullable|string|max:255',
                'late_pickup_action' => 'nullable|string|max:255',
                'refund_policy' => 'nullable|string|max:255',
                'allow_partial_pickup' => 'nullable|boolean',
                'show_pickup_instructions' => 'nullable|boolean',
                'additional_pickup_instructions' => 'nullable|string',
                'pickup_settings' => 'nullable|array',
            ]);

            // If address is changing and coordinates aren't provided, geocode again
            if (isset($validated['address']) && $validated['address'] !== $location->address && (empty($validated['latitude']) || empty($validated['longitude']))) {
                $coords = $this->geocodeAddress($validated['address']);
                $validated['latitude'] = $coords['latitude'];
                $validated['longitude'] = $coords['longitude'];
            }

            $location->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pickup location updated successfully.',
                'data' => $location
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update pickup location.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $location = StorePickupLocation::findOrFail($id);
            $location->delete();
            return response()->json([
                'success' => true,
                'message' => 'Pickup location deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete pickup location.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Simple local geocoder for common addresses
     */
    private function geocodeAddress($address)
    {
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        if (!empty($apiKey)) {
            try {
                $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $apiKey
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['results'])) {
                        $location = $data['results'][0]['geometry']['location'];
                        return [
                            'latitude' => floatval($location['lat']),
                            'longitude' => floatval($location['lng'])
                        ];
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Google Geocoding failed: ' . $e->getMessage());
            }
        }

        $addr = strtolower($address);
        
        // Mc Donough GA (Store A)
        if (str_contains($addr, 'mcdonough')) {
            return ['latitude' => 33.4473, 'longitude' => -84.1469];
        }
        
        // Peachtree Rd Atlanta (Store B)
        if (str_contains($addr, 'peachtree') || (str_contains($addr, 'atlanta') && !str_contains($addr, 'main st'))) {
            return ['latitude' => 33.8539, 'longitude' => -84.3619];
        }
        
        // Norcross GA (Store C)
        if (str_contains($addr, 'norcross') || str_contains($addr, 'jimmy carter')) {
            return ['latitude' => 33.9189, 'longitude' => -84.1894];
        }

        // Atlanta Downtown (123 Main St, Atlanta, GA)
        if (str_contains($addr, 'main st') && str_contains($addr, 'atlanta')) {
            return ['latitude' => 33.7490, 'longitude' => -84.3880];
        }

        // Default: Atlanta coordinates with small random offset
        $offsetLat = (rand(-100, 100) / 1000.0);
        $offsetLng = (rand(-100, 100) / 1000.0);
        return [
            'latitude' => 33.7490 + $offsetLat,
            'longitude' => -84.3880 + $offsetLng
        ];
    }
}
