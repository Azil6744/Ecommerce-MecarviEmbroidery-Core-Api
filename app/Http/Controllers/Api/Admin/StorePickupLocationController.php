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

            // If address is changing or coordinates are empty, geocode again
            if ((isset($validated['address']) && $validated['address'] !== $location->address) || empty($location->latitude) || empty($location->longitude)) {
                if (empty($validated['latitude']) || empty($validated['longitude'])) {
                    $targetAddr = $validated['address'] ?? $location->address;
                    $coords = $this->geocodeAddress($targetAddr);
                    if (!empty($coords)) {
                        $validated['latitude'] = $coords['latitude'];
                        $validated['longitude'] = $coords['longitude'];
                    }
                }
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

        // Extract 5-digit US ZIP code if present
        $extractedZip = null;
        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $zm)) {
            $extractedZip = $zm[1];
        }

        // OpenStreetMap Nominatim
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MecarviEcommerce/1.0 (contact@mecarviembroidery.com)'
            ])->timeout(3)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $address,
                'format' => 'json',
                'limit' => 1
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
                    return [
                        'latitude' => floatval($data[0]['lat']),
                        'longitude' => floatval($data[0]['lon'])
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Admin Nominatim Geocoding failed: ' . $e->getMessage());
        }

        // Photon (Komoot)
        try {
            $response = Http::timeout(3)->get('https://photon.komoot.io/api/', [
                'q' => $address,
                'limit' => 1
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['features'][0]['geometry']['coordinates'])) {
                    $coords = $data['features'][0]['geometry']['coordinates'];
                    return [
                        'latitude' => floatval($coords[1]),
                        'longitude' => floatval($coords[0])
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Admin Photon Geocoding failed: ' . $e->getMessage());
        }

        // US Zip Code lookup (Zippopotam.us)
        if (!empty($extractedZip)) {
            try {
                $response = Http::timeout(3)->get("https://api.zippopotam.us/us/{$extractedZip}");
                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['places'][0]['latitude']) && !empty($data['places'][0]['longitude'])) {
                        return [
                            'latitude' => floatval($data['places'][0]['latitude']),
                            'longitude' => floatval($data['places'][0]['longitude'])
                        ];
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Admin Zip Geocoding failed: ' . $e->getMessage());
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

        // State centroid fallback
        $stateCentroids = [
            'AL' => [32.806671, -86.791130], 'AK' => [61.370716, -152.404419], 'AZ' => [33.729759, -111.431221],
            'AR' => [34.969704, -92.373123], 'CA' => [36.116203, -119.681564], 'CO' => [39.059811, -105.311104],
            'CT' => [41.597782, -72.755371], 'DE' => [39.318523, -75.507141], 'FL' => [27.766279, -81.686783],
            'GA' => [33.040619, -83.643074], 'HI' => [21.094318, -157.498337], 'ID' => [44.240459, -114.478828],
            'IL' => [40.349457, -88.986137], 'IN' => [39.849426, -86.258278], 'IA' => [42.011539, -93.210526],
            'KS' => [38.526600, -96.726486], 'KY' => [37.668140, -84.670067], 'LA' => [31.169546, -91.867805],
            'ME' => [44.693947, -69.381927], 'MD' => [39.063946, -76.802101], 'MA' => [42.230171, -71.530106],
            'MI' => [43.326618, -84.536095], 'MN' => [45.694454, -93.900192], 'MS' => [32.741646, -89.678696],
            'MO' => [38.456085, -92.288368], 'MT' => [46.921925, -110.454353], 'NE' => [41.125370, -98.268082],
            'NV' => [38.313515, -117.055374], 'NH' => [43.452492, -71.563896], 'NJ' => [40.298904, -74.521011],
            'NM' => [34.840515, -106.248482], 'NY' => [42.165726, -74.948051], 'NC' => [35.630066, -79.806419],
            'ND' => [47.528912, -99.784012], 'OH' => [40.388783, -82.764915], 'OK' => [35.565342, -96.928917],
            'OR' => [44.572021, -122.070938], 'PA' => [40.590752, -77.209755], 'RI' => [41.680893, -71.511780],
            'SC' => [33.856892, -80.945007], 'SD' => [44.299782, -99.438828], 'TN' => [35.747845, -86.692345],
            'TX' => [31.054487, -97.563461], 'UT' => [40.150032, -111.862434], 'VT' => [44.045876, -72.710686],
            'VA' => [37.769337, -78.169968], 'WA' => [47.400902, -121.490494], 'WV' => [38.491226, -80.954453],
            'WI' => [44.268543, -89.616508], 'WY' => [42.755966, -107.302490]
        ];

        $upperAddr = strtoupper($address);
        foreach ($stateCentroids as $st => $c) {
            if (preg_match('/\b' . $st . '\b/', $upperAddr)) {
                return ['latitude' => $c[0], 'longitude' => $c[1]];
            }
        }

        return null;
    }
}
