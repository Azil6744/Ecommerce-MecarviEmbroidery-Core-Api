<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\StorePickupLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublicPickupLocationController extends Controller
{
    /**
     * Get eligible store pickup locations based on distance from customer address
     */
    public function getEligibleLocations(Request $request)
    {
        try {
            $request->validate([
                'address' => 'required|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'zip' => 'nullable|string',
                'zip_code' => 'nullable|string',
                'postal_code' => 'nullable|string',
                'country' => 'nullable|string',
            ]);

            $customerAddress = $request->input('address');
            $customerLat = $request->input('latitude');
            $customerLng = $request->input('longitude');
            $city = $request->input('city');
            $state = $request->input('state');
            $zip = $request->input('zip') ?: $request->input('zip_code') ?: $request->input('postal_code');
            $country = $request->input('country');

            // Geocode customer address if coordinates are not provided
            if (empty($customerLat) || empty($customerLng)) {
                $coords = $this->geocodeAddress($customerAddress, $city, $state, $zip, $country);
                if (!empty($coords)) {
                    $customerLat = $coords['latitude'];
                    $customerLng = $coords['longitude'];
                }
            }

            // If coordinates cannot be determined, no store can be confirmed within range
            if (empty($customerLat) || empty($customerLng)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Retrieve all active store pickup locations
            $stores = StorePickupLocation::active()
                ->pickupEnabled()
                ->get();

            $eligibleStores = [];

            foreach ($stores as $store) {
                // If store has no coordinates, skip
                if (empty($store->latitude) || empty($store->longitude)) {
                    continue;
                }

                // Calculate real geographic distance using Haversine formula
                $distance = $this->haversineDistance(
                    $customerLat,
                    $customerLng,
                    $store->latitude,
                    $store->longitude
                );

                // Use store's max pickup radius or default 10.0 miles
                $maxRadius = $store->max_pickup_radius ?? 10.0;

                if ($distance <= $maxRadius) {
                    $storeData = $store->toArray();
                    $storeData['distance'] = round($distance, 1);
                    $eligibleStores[] = $storeData;
                }
            }

            // Sort by distance (closest first)
            usort($eligibleStores, function ($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });

            // Limit to max 3 stores
            $eligibleStores = array_slice($eligibleStores, 0, 3);

            return response()->json([
                'success' => true,
                'data' => $eligibleStores
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate eligible pickup locations.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Haversine formula to calculate distance in miles
     */
    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 3959.0; // miles

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2.0) * sin($latDelta / 2.0) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2.0) * sin($lonDelta / 2.0);

        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return $earthRadius * $c;
    }

    /**
     * Geocodes customer address using multi-tier geocoders
     */
    private function geocodeAddress($address, $city = null, $state = null, $zip = null, $country = null)
    {
        // 1. Google Maps Geocoding API if key configured
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        if (!empty($apiKey)) {
            try {
                $response = Http::timeout(3)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $apiKey
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['results'][0]['geometry']['location'])) {
                        $loc = $data['results'][0]['geometry']['location'];
                        return [
                            'latitude' => floatval($loc['lat']),
                            'longitude' => floatval($loc['lng'])
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::error('Google Geocoding failed: ' . $e->getMessage());
            }
        }

        // Extract 5-digit US ZIP code if present
        $extractedZip = null;
        if (!empty($zip) && preg_match('/^\d{5}/', trim($zip), $zm)) {
            $extractedZip = $zm[0];
        } elseif (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $zm)) {
            $extractedZip = $zm[1];
        }

        // 2. OpenStreetMap Nominatim full query
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
            Log::warning('Nominatim Geocoding failed: ' . $e->getMessage());
        }

        // 3. Photon (Komoot) full address
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
            Log::warning('Photon Geocoding failed: ' . $e->getMessage());
        }

        // 4. US Zip Code lookup (Zippopotam.us)
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
                Log::warning('Zip Geocoding failed: ' . $e->getMessage());
            }
        }

        // 5. Fallback Nominatim with City/State/Zip
        $fallbackQuery = trim(implode(', ', array_filter([$city, $state, $extractedZip, $country ?: 'United States'])));
        if (empty($fallbackQuery) && !empty($extractedZip)) {
            $fallbackQuery = $extractedZip . ', United States';
        }

        if (!empty($fallbackQuery)) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'MecarviEcommerce/1.0 (contact@mecarviembroidery.com)'
                ])->timeout(3)->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $fallbackQuery,
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
                Log::warning('Fallback Nominatim failed: ' . $e->getMessage());
            }
        }

        // 6. State centroid fallback to ensure correct region / state boundary
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

        $upperAddr = strtoupper($address . ' ' . $state);
        foreach ($stateCentroids as $st => $c) {
            if (preg_match('/\b' . $st . '\b/', $upperAddr)) {
                return ['latitude' => $c[0], 'longitude' => $c[1]];
            }
        }

        return null;
    }
}
