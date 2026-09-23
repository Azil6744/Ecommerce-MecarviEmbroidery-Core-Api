<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssetSettingController extends Controller
{
    /**
     * Get all asset settings grouped by category_type
     */
    public function index(Request $request)
    {
        // Seed default records if empty
        if (AssetSetting::count() === 0) {
            $this->seedDefaults();
        }

        $query = AssetSetting::orderBy('sort_order', 'asc')->orderBy('name', 'asc');

        if ($request->filled('category_type')) {
            $query->where('category_type', $request->category_type);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        $settings = $query->get();

        // Group by category_type
        $grouped = [];
        $categories = [
            'asset_categories',
            'maintenance_types',
            'service_types',
            'service_providers',
            'document_types',
            'locations',
            'brands',
            'departments',
            'assigned_to',
            'asset_statuses',
            'conditions',
            'purchase_types',
        ];

        foreach ($categories as $cat) {
            $grouped[$cat] = $settings->where('category_type', $cat)->values();
        }

        return response()->json([
            'status' => 'success',
            'data' => $grouped,
            'counts' => array_map(function ($items) {
                return count($items);
            }, $grouped),
        ]);
    }

    /**
     * Store a new asset setting
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_type' => 'required|string',
            'name' => 'required|string|max:255',
            'dot_color' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        $data['dot_color'] = $data['dot_color'] ?? 'blue';

        $setting = AssetSetting::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Setting created successfully',
            'data' => $setting,
        ], 201);
    }

    /**
     * Update an asset setting
     */
    public function update(Request $request, $id)
    {
        $setting = AssetSetting::find($id);

        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Setting not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'dot_color' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $setting->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Setting updated successfully',
            'data' => $setting,
        ]);
    }

    /**
     * Delete an asset setting
     */
    public function destroy($id)
    {
        $setting = AssetSetting::find($id);

        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Setting not found'], 404);
        }

        $setting->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Setting deleted successfully',
        ]);
    }

    /**
     * Seed realistic default settings matching the design
     */
    private function seedDefaults()
    {
        $defaults = [
            // 1. Asset Categories (12)
            'asset_categories' => [
                ['name' => 'Production Equipment', 'dot_color' => 'blue'],
                ['name' => 'Office Equipment', 'dot_color' => 'blue'],
                ['name' => 'IT Equipment', 'dot_color' => 'red'],
                ['name' => 'Furniture & Fixtures', 'dot_color' => 'green'],
                ['name' => 'Vehicles', 'dot_color' => 'purple'],
                ['name' => 'Packaging Machinery', 'dot_color' => 'cyan'],
                ['name' => 'Printing Equipment', 'dot_color' => 'orange'],
                ['name' => 'Security Systems', 'dot_color' => 'emerald'],
                ['name' => 'Warehouse Tools', 'dot_color' => 'yellow'],
                ['name' => 'Audio / Visual', 'dot_color' => 'blue'],
                ['name' => 'Facility Maintenance', 'dot_color' => 'purple'],
                ['name' => 'Quality Inspection', 'dot_color' => 'red'],
            ],

            // 2. Maintenance Types (8)
            'maintenance_types' => [
                ['name' => 'Preventive Maintenance', 'dot_color' => 'blue'],
                ['name' => 'Corrective Maintenance', 'dot_color' => 'green'],
                ['name' => 'Routine Maintenance', 'dot_color' => 'purple'],
                ['name' => 'Inspection', 'dot_color' => 'blue'],
                ['name' => 'Calibration', 'dot_color' => 'orange'],
                ['name' => 'Emergency Repair', 'dot_color' => 'red'],
                ['name' => 'Software Update', 'dot_color' => 'cyan'],
                ['name' => 'Safety Audit', 'dot_color' => 'emerald'],
            ],

            // 3. Service Types (8)
            'service_types' => [
                ['name' => 'Routine Service', 'dot_color' => 'blue'],
                ['name' => 'Repair Service', 'dot_color' => 'red'],
                ['name' => 'Installation', 'dot_color' => 'blue'],
                ['name' => 'Inspection Service', 'dot_color' => 'green'],
                ['name' => 'Calibration Service', 'dot_color' => 'orange'],
                ['name' => 'Belt & Gear Overhaul', 'dot_color' => 'purple'],
                ['name' => 'Oil & Lubrication', 'dot_color' => 'cyan'],
                ['name' => 'Electronics Diagnostic', 'dot_color' => 'yellow'],
            ],

            // 4. Service Providers (14)
            'service_providers' => [
                ['name' => 'Tajima Authorized Service', 'contact_person' => 'Daniel Sato', 'phone' => '+1 718 555-0199', 'dot_color' => 'blue'],
                ['name' => 'ATL Machine Repair', 'contact_person' => 'Kevin White', 'phone' => '+1 404 555-0145', 'dot_color' => 'red'],
                ['name' => 'Mecarvi Internal Team', 'contact_person' => 'Monique Brown', 'phone' => '+1 470 555-0167', 'dot_color' => 'purple'],
                ['name' => 'StitchPro Services', 'contact_person' => 'Jason Lee', 'phone' => '+1 305 555-0123', 'dot_color' => 'green'],
                ['name' => 'Johnson Electronics', 'contact_person' => 'Mark Johnson', 'phone' => '+1 678 555-0188', 'dot_color' => 'yellow'],
                ['name' => 'Brother Technicians', 'contact_person' => 'Alex Vance', 'phone' => '+1 212 555-0144', 'dot_color' => 'cyan'],
                ['name' => 'Ricoma Care Direct', 'contact_person' => 'Sarah Connor', 'phone' => '+1 305 555-0199', 'dot_color' => 'blue'],
                ['name' => 'Barudan USA Support', 'contact_person' => 'John Doe', 'phone' => '+1 800 555-0122', 'dot_color' => 'orange'],
                ['name' => 'Metro Power Systems', 'contact_person' => 'Sam Harris', 'phone' => '+1 404 555-0177', 'dot_color' => 'emerald'],
                ['name' => 'Precision Needle Corp', 'contact_person' => 'Lisa Wong', 'phone' => '+1 646 555-0133', 'dot_color' => 'red'],
                ['name' => 'Atlanta Industrial HVAC', 'contact_person' => 'Tom Hardy', 'phone' => '+1 770 555-0188', 'dot_color' => 'purple'],
                ['name' => 'East Coast Logistics', 'contact_person' => 'Dave Miller', 'phone' => '+1 718 555-0166', 'dot_color' => 'green'],
                ['name' => 'Global Tech Diagnostics', 'contact_person' => 'Emily Blunt', 'phone' => '+1 212 555-0155', 'dot_color' => 'yellow'],
                ['name' => 'Apex Machine Works', 'contact_person' => 'Bruce Wayne', 'phone' => '+1 404 555-0190', 'dot_color' => 'blue'],
            ],

            // 5. Document Types (9)
            'document_types' => [
                ['name' => 'Service Report', 'dot_color' => 'cyan'],
                ['name' => 'Invoice', 'dot_color' => 'red'],
                ['name' => 'Receipt', 'dot_color' => 'green'],
                ['name' => 'Warranty Document', 'dot_color' => 'purple'],
                ['name' => 'Contract / Proposal', 'dot_color' => 'yellow'],
                ['name' => 'User Manual', 'dot_color' => 'blue'],
                ['name' => 'Calibration Certificate', 'dot_color' => 'orange'],
                ['name' => 'Safety Inspection Pass', 'dot_color' => 'emerald'],
                ['name' => 'Insurance Policy', 'dot_color' => 'pink'],
            ],

            // 6. Locations (10)
            'locations' => [
                ['name' => 'Atlanta - HQ', 'address' => '1234 Metropolitan Pkwy SW Atlanta, GA 30310', 'dot_color' => 'green'],
                ['name' => 'Brooklyn Store', 'address' => '850 Atlantic Ave Brooklyn, NY 11207', 'dot_color' => 'red'],
                ['name' => 'Orlando Store', 'address' => '7265 W Colonial Dr Orlando, FL 32818', 'dot_color' => 'blue'],
                ['name' => 'Grenada Store', 'address' => "Grand Anse, St. George's Grenada", 'dot_color' => 'orange'],
                ['name' => 'Smyrna Store', 'address' => '2850 Spring Rd SE Smyrna, GA 30080', 'dot_color' => 'purple'],
                ['name' => 'Forest Park Facility', 'address' => '4500 Jonesboro Rd, Forest Park, GA 30297', 'dot_color' => 'cyan'],
                ['name' => 'Manhattan Showroom', 'address' => '350 5th Ave, New York, NY 10118', 'dot_color' => 'yellow'],
                ['name' => 'Miami Distribution Hub', 'address' => '1200 NW 78th Ave, Miami, FL 33126', 'dot_color' => 'emerald'],
                ['name' => 'Savannah Port Warehouse', 'address' => '200 Ocean Terminal, Savannah, GA 31401', 'dot_color' => 'blue'],
                ['name' => 'Dallas Regional Depot', 'address' => '1500 N Stemmons Fwy, Dallas, TX 75207', 'dot_color' => 'red'],
            ],

            // 7. Brands (12)
            'brands' => [
                ['name' => 'Tajima', 'dot_color' => 'green'],
                ['name' => 'Brother', 'dot_color' => 'red'],
                ['name' => 'Barudan', 'dot_color' => 'blue'],
                ['name' => 'Ricoma', 'dot_color' => 'purple'],
                ['name' => 'Happy', 'dot_color' => 'orange'],
                ['name' => 'SWF', 'dot_color' => 'cyan'],
                ['name' => 'Melco', 'dot_color' => 'yellow'],
                ['name' => 'ZSK', 'dot_color' => 'emerald'],
                ['name' => 'Toyota Embroidery', 'dot_color' => 'blue'],
                ['name' => 'Janome Industrial', 'dot_color' => 'red'],
                ['name' => 'Singer Heavy Duty', 'dot_color' => 'purple'],
                ['name' => 'Juki Automation', 'dot_color' => 'green'],
            ],

            // 8. Departments (8)
            'departments' => [
                ['name' => 'Embroidery', 'dot_color' => 'green'],
                ['name' => 'Printing', 'dot_color' => 'blue'],
                ['name' => 'Signs', 'dot_color' => 'purple'],
                ['name' => 'Creative', 'dot_color' => 'orange'],
                ['name' => 'Operations', 'dot_color' => 'cyan'],
                ['name' => 'Quality Assurance', 'dot_color' => 'emerald'],
                ['name' => 'Shipping & Receiving', 'dot_color' => 'yellow'],
                ['name' => 'Executive & Admin', 'dot_color' => 'red'],
            ],

            // 9. Assigned To (24)
            'assigned_to' => [
                ['name' => 'Daniel Sato', 'email' => 'daniel@mecarvi.com', 'dot_color' => 'blue'],
                ['name' => 'Monique Brown', 'email' => 'monique@mecarvi.com', 'dot_color' => 'red'],
                ['name' => 'Chris Taylor', 'email' => 'chris@mecarvi.com', 'dot_color' => 'green'],
                ['name' => 'Ashley Green', 'email' => 'ashley@mecarvi.com', 'dot_color' => 'purple'],
                ['name' => 'Robert King', 'email' => 'robert@mecarvi.com', 'dot_color' => 'orange'],
                ['name' => 'Jessica Alba', 'email' => 'jessica@mecarvi.com', 'dot_color' => 'cyan'],
                ['name' => 'David Beckham', 'email' => 'david@mecarvi.com', 'dot_color' => 'blue'],
                ['name' => 'Emma Watson', 'email' => 'emma@mecarvi.com', 'dot_color' => 'emerald'],
                ['name' => 'James Bond', 'email' => 'james@mecarvi.com', 'dot_color' => 'yellow'],
                ['name' => 'Sarah Connor', 'email' => 'sarah@mecarvi.com', 'dot_color' => 'red'],
                ['name' => 'John Wick', 'email' => 'john@mecarvi.com', 'dot_color' => 'purple'],
                ['name' => 'Tony Stark', 'email' => 'tony@mecarvi.com', 'dot_color' => 'green'],
                ['name' => 'Peter Parker', 'email' => 'peter@mecarvi.com', 'dot_color' => 'orange'],
                ['name' => 'Natasha Romanoff', 'email' => 'natasha@mecarvi.com', 'dot_color' => 'red'],
                ['name' => 'Bruce Banner', 'email' => 'bruce@mecarvi.com', 'dot_color' => 'emerald'],
                ['name' => 'Clark Kent', 'email' => 'clark@mecarvi.com', 'dot_color' => 'blue'],
                ['name' => 'Diana Prince', 'email' => 'diana@mecarvi.com', 'dot_color' => 'yellow'],
                ['name' => 'Barry Allen', 'email' => 'barry@mecarvi.com', 'dot_color' => 'red'],
                ['name' => 'Arthur Curry', 'email' => 'arthur@mecarvi.com', 'dot_color' => 'cyan'],
                ['name' => 'Victor Stone', 'email' => 'victor@mecarvi.com', 'dot_color' => 'purple'],
                ['name' => 'Hal Jordan', 'email' => 'hal@mecarvi.com', 'dot_color' => 'green'],
                ['name' => 'Oliver Queen', 'email' => 'oliver@mecarvi.com', 'dot_color' => 'emerald'],
                ['name' => 'Kara Danvers', 'email' => 'kara@mecarvi.com', 'dot_color' => 'blue'],
                ['name' => 'Selina Kyle', 'email' => 'selina@mecarvi.com', 'dot_color' => 'purple'],
            ],

            // 10. Asset Statuses (6)
            'asset_statuses' => [
                ['name' => 'In Use', 'dot_color' => 'green'],
                ['name' => 'In Maintenance', 'dot_color' => 'orange'],
                ['name' => 'Out of Service', 'dot_color' => 'red'],
                ['name' => 'Retired', 'dot_color' => 'purple'],
                ['name' => 'Disposed', 'dot_color' => 'blue'],
                ['name' => 'Reserved', 'dot_color' => 'cyan'],
            ],

            // 11. Conditions (7)
            'conditions' => [
                ['name' => 'New', 'dot_color' => 'blue'],
                ['name' => 'Excellent', 'dot_color' => 'green'],
                ['name' => 'Good', 'dot_color' => 'cyan'],
                ['name' => 'Fair', 'dot_color' => 'orange'],
                ['name' => 'Poor', 'dot_color' => 'amber'],
                ['name' => 'Damaged', 'dot_color' => 'red'],
                ['name' => 'Needs Repair', 'dot_color' => 'purple'],
            ],

            // 12. Purchase Types (6)
            'purchase_types' => [
                ['name' => 'Purchased', 'dot_color' => 'green'],
                ['name' => 'Financed', 'dot_color' => 'blue'],
                ['name' => 'Leased', 'dot_color' => 'purple'],
                ['name' => 'Rented', 'dot_color' => 'red'],
                ['name' => 'Donated', 'dot_color' => 'orange'],
                ['name' => 'Other', 'dot_color' => 'cyan'],
            ],
        ];

        foreach ($defaults as $cat => $items) {
            foreach ($items as $idx => $item) {
                AssetSetting::create(array_merge($item, [
                    'category_type' => $cat,
                    'sort_order' => $idx + 1,
                ]));
            }
        }
    }
}
