<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyAsset;
use App\Models\AssetActivityLog;
use App\Models\AssetMaintenanceRecord;
use App\Models\AssetFinancialRecord;
use App\Models\AssetNote;
use App\Models\AssetDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AssetController extends Controller
{
    /**
     * Get paginated assets with filtering and search
     */
    public function index(Request $request)
    {
        $query = CompanyAsset::query();

        // Search filter
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('asset_tag', 'LIKE', "%{$search}%")
                  ->orWhere('brand', 'LIKE', "%{$search}%")
                  ->orWhere('model', 'LIKE', "%{$search}%")
                  ->orWhere('serial_number', 'LIKE', "%{$search}%")
                  ->orWhere('location', 'LIKE', "%{$search}%")
                  ->orWhere('department', 'LIKE', "%{$search}%")
                  ->orWhere('category', 'LIKE', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Category filter
        if ($request->filled('category') && $request->category !== 'all' && $request->category !== 'All Assets') {
            $cat = $request->category;
            if ($cat === 'Embroidery Machines') {
                $query->where(function ($q) {
                    $q->where('category', 'Embroidery Machines')
                      ->orWhere('category', 'Production Equipment');
                });
            } else {
                $query->where('category', $cat);
            }
        }

        // Location filter
        if ($request->filled('location') && $request->location !== 'all' && $request->location !== 'All Locations') {
            $query->where('location', $request->location);
        }

        // Sorting: default to id asc
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->input('per_page', 8);
        $assets = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $assets->items(),
            'pagination' => [
                'total' => $assets->total(),
                'per_page' => $assets->perPage(),
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'from' => $assets->firstItem(),
                'to' => $assets->lastItem(),
            ]
        ]);
    }

    /**
     * Get KPI stats and category/location summaries
     */
    public function stats(Request $request)
    {
        $total = CompanyAsset::count();
        $inUse = CompanyAsset::where('status', 'in_use')->count();
        $underMaintenance = CompanyAsset::where('status', 'under_maintenance')->count();
        $outOfService = CompanyAsset::where('status', 'out_of_service')->count();

        // Category breakdown
        $categories = [
            'Embroidery Machines' => CompanyAsset::where('category', 'Embroidery Machines')->count(),
            'Production Equipment' => CompanyAsset::where('category', 'Production Equipment')->count(),
            'Computers & IT' => CompanyAsset::where('category', 'Computers & IT')->count(),
            'Office Equipment' => CompanyAsset::where('category', 'Office Equipment')->count(),
            'Furniture & Fixtures' => CompanyAsset::where('category', 'Furniture & Fixtures')->count(),
            'Other Equipment' => CompanyAsset::where('category', 'Other Equipment')->count(),
            'All Assets' => $total,
        ];

        $otherCats = CompanyAsset::select('category')->distinct()->pluck('category');
        foreach ($otherCats as $c) {
            if ($c && !isset($categories[$c])) {
                $categories[$c] = CompanyAsset::where('category', $c)->count();
            }
        }

        // Locations list
        $locations = CompanyAsset::whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->pluck('location')
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_assets' => $total,
                'in_use' => $inUse,
                'under_maintenance' => $underMaintenance,
                'out_of_service' => $outOfService,
                'categories' => $categories,
                'locations' => $locations,
            ]
        ]);
    }

    /**
     * Get upcoming maintenance cards
     */
    public function upcomingMaintenance()
    {
        $mockupItems = [
            [
                'id' => 1,
                'asset_id' => 3,
                'asset_tag' => 'ASSET-004',
                'asset_name' => 'Office Printer',
                'service_type' => 'Routine Maintenance',
                'date_badge' => 'APR 10',
                'days_left' => 3,
                'days_label' => '3 days',
                'badge_color' => 'pink',
                'image' => '/images/assets/maint_printer.png',
            ],
            [
                'id' => 2,
                'asset_id' => 4,
                'asset_tag' => 'ASSET-007',
                'asset_name' => 'Air Compressor',
                'service_type' => 'Service Due',
                'date_badge' => 'APR 15',
                'days_left' => 8,
                'days_label' => '8 days',
                'badge_color' => 'blue',
                'image' => '/images/assets/maint_compressor.png',
            ],
            [
                'id' => 3,
                'asset_id' => 1,
                'asset_tag' => 'ASSET-001',
                'asset_name' => 'Tajima Machine',
                'service_type' => 'Routine Check',
                'date_badge' => 'APR 22',
                'days_left' => 15,
                'days_label' => '15 days',
                'badge_color' => 'orange',
                'image' => '/images/assets/maint_tajima.png',
            ],
            [
                'id' => 4,
                'asset_id' => 8,
                'asset_tag' => 'ASSET-010',
                'asset_name' => 'HVAC Unit',
                'service_type' => 'Filter Replacement',
                'date_badge' => 'MAY 05',
                'days_left' => 22,
                'days_label' => '22 days',
                'badge_color' => 'purple',
                'image' => '/images/assets/maint_hvac.png',
            ],
        ];

        return response()->json([
            'status' => 'success',
            'data' => $mockupItems,
        ]);
    }


    /**
     * Get recent activity logs
     */
    public function recentActivity()
    {
        $logs = AssetActivityLog::orderBy('created_at', 'desc')->take(10)->get();

        $formatted = $logs->map(function ($log) {
            $diff = $log->created_at ? $log->created_at->diffForHumans() : 'Recently';
            return [
                'id' => $log->id,
                'action_type' => $log->action_type,
                'title' => $log->title,
                'target_name' => $log->target_name,
                'before_value' => $log->before_value,
                'after_value' => $log->after_value,
                'location' => $log->location,
                'reference_number' => $log->reference_number,
                'description' => $log->description,
                'performed_by' => $log->performed_by,
                'time_ago' => $diff . ' by ' . $log->performed_by,
                'created_at' => $log->created_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $formatted,
        ]);
    }

    /**
     * Generate next asset tag (e.g. ASSET-026)
     */
    public function generateTag()
    {
        $latest = CompanyAsset::orderBy('id', 'desc')->first();
        $nextNum = $latest ? ($latest->id + 1) : 1;
        $tag = 'ASSET-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

        while (CompanyAsset::where('asset_tag', $tag)->exists()) {
            $nextNum++;
            $tag = 'ASSET-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'tag' => $tag
            ]
        ]);
    }

    /**
     * Store new asset
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'asset_tag' => 'nullable|string|max:50|unique:company_assets,asset_tag',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'purchase_date' => 'nullable|date',
            'purchase_cost' => 'nullable|numeric',
            'location' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'assigned_to' => 'nullable|string|max:255',
            'condition' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'purchase_type' => 'nullable|string|max:50',
            'manufacturer' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:255',
            'invoice_date' => 'nullable|date',
            'vendor_name_address' => 'nullable|string',
            'warranty_status' => 'nullable|string|max:50',
            'warranty_provider' => 'nullable|string|max:255',
            'warranty_start_date' => 'nullable|date',
            'warranty_expiration_date' => 'nullable|date',
            'warranty_reference_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'images' => 'nullable|array',
            'documents' => 'nullable|array',
            'has_maintenance_schedule' => 'nullable|boolean',
            'service_type' => 'nullable|string',
            'service_frequency' => 'nullable|string',
            'next_service_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();

        // Auto generate tag if omitted
        if (empty($data['asset_tag'])) {
            $latest = CompanyAsset::orderBy('id', 'desc')->first();
            $nextNum = $latest ? ($latest->id + 1) : 1;
            $tag = 'ASSET-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            while (CompanyAsset::where('asset_tag', $tag)->exists()) {
                $nextNum++;
                $tag = 'ASSET-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            }
            $data['asset_tag'] = $tag;
        }

        if (empty($data['status'])) {
            $data['status'] = 'in_use';
        }

        if (empty($data['images']) || !is_array($data['images'])) {
            $data['images'] = [];
        }

        $asset = CompanyAsset::create($data);

        // Auto record initial purchase in financial records
        if (!empty($asset->purchase_cost) && $asset->purchase_cost > 0) {
            $vendorName = !empty($asset->vendor_name_address)
                ? $asset->vendor_name_address
                : (!empty($asset->brand) ? "{$asset->brand} Dealer" : 'Equipment Vendor');

            AssetFinancialRecord::create([
                'asset_id' => $asset->id,
                'expense_type' => 'Purchase',
                'description' => "Initial purchase of {$asset->name}",
                'invoice_number' => $asset->invoice_number ?? 'INV-' . date('Y') . '-' . str_pad($asset->id, 3, '0', STR_PAD_LEFT),
                'vendor_name' => $vendorName,
                'amount' => $asset->purchase_cost,
                'expense_date' => $asset->purchase_date ?? date('Y-m-d'),
            ]);
        }

        // Auto create maintenance schedule if specified
        if (!empty($asset->has_maintenance_schedule) && !empty($asset->next_service_date)) {
            AssetMaintenanceRecord::create([
                'asset_id' => $asset->id,
                'record_type' => 'schedule',
                'maintenance_title' => $asset->service_type ?? 'Scheduled Service',
                'service_type' => $asset->service_type ?? 'Preventive Maintenance',
                'description' => "Initial scheduled service ({$asset->service_frequency})",
                'status' => 'Scheduled',
                'assigned_to' => $asset->assigned_to ?? 'Technician',
                'service_date' => $asset->next_service_date,
            ]);
        }

        // Log activity
        AssetActivityLog::create([
            'asset_id' => $asset->id,
            'action_type' => 'created',
            'title' => 'New asset added',
            'target_name' => "{$asset->name} ({$asset->asset_tag})",
            'before_value' => '—',
            'after_value' => 'Asset Record',
            'location' => $asset->location ?? 'Main Facility',
            'reference_number' => $asset->asset_tag,
            'description' => "Asset {$asset->name} ({$asset->asset_tag}) was added to {$asset->category}.",
            'performed_by' => 'Admin',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Asset created successfully',
            'data' => $asset,
        ], 201);
    }

    /**
     * Show single asset with relations
     */
    public function show($id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Asset not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $asset,
        ]);
    }

    /**
     * Update asset
     */
    public function update(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Asset not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'asset_tag' => 'sometimes|required|string|max:50|unique:company_assets,asset_tag,' . $asset->id,
            'purchase_cost' => 'nullable|numeric',
            'images' => 'nullable|array',
            'documents' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $oldStatus = $asset->status;
        $oldLocation = $asset->location;
        $asset->update($request->all());

        // Log status change
        if ($request->filled('status') && $request->status !== $oldStatus) {
            $newStatus = $request->status;
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'status_updated',
                'title' => 'Status Updated',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => ucwords(str_replace('_', ' ', $oldStatus)),
                'after_value' => ucwords(str_replace('_', ' ', $newStatus)),
                'location' => $asset->location ?? 'Main Facility',
                'reference_number' => 'STA-' . date('Y') . '-' . str_pad($asset->id, 3, '0', STR_PAD_LEFT),
                'description' => "Asset status changed from {$oldStatus} to {$newStatus}.",
                'performed_by' => 'Admin User',
            ]);
        } elseif ($request->filled('location') && $request->location !== $oldLocation) {
            // Log location change
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'location_changed',
                'title' => 'Location Changed',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => $oldLocation ?? 'Unassigned',
                'after_value' => $request->location,
                'location' => $request->location,
                'reference_number' => 'TRF-' . date('Y') . '-' . str_pad($asset->id, 3, '0', STR_PAD_LEFT),
                'description' => "Asset moved to new location {$request->location}.",
                'performed_by' => 'Admin User',
            ]);
        } else {
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'info_updated',
                'title' => 'Asset information updated',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => 'Previous details',
                'after_value' => 'Updated details',
                'location' => $asset->location ?? 'Main Facility',
                'reference_number' => $asset->asset_tag,
                'description' => "Asset information was updated.",
                'performed_by' => 'Admin User',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Asset updated successfully',
            'data' => $asset,
        ]);
    }

    /**
     * Delete asset
     */
    public function destroy($id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Asset not found',
            ], 404);
        }

        $name = $asset->name;
        $tag = $asset->asset_tag;
        $asset->delete();

        AssetActivityLog::create([
            'asset_id' => null,
            'action_type' => 'deleted',
            'title' => 'Asset deleted',
            'target_name' => "{$name} ({$tag})",
            'before_value' => 'Active Record',
            'after_value' => 'Deleted',
            'location' => 'System',
            'reference_number' => $tag,
            'description' => "Asset {$name} ({$tag}) was removed from inventory.",
            'performed_by' => 'Admin',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Asset deleted successfully',
        ]);
    }

    /**
     * -------------------------------------------------------------
     * TAB 2: MAINTENANCE ENDPOINTS
     * -------------------------------------------------------------
     */
    public function maintenance($id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        // Fetch or seed realistic schedule and history
        $schedule = AssetMaintenanceRecord::where('asset_id', $asset->id)
            ->where('record_type', 'schedule')
            ->orderBy('service_date', 'asc')
            ->get();

        $history = AssetMaintenanceRecord::where('asset_id', $asset->id)
            ->where('record_type', 'history')
            ->orderBy('service_date', 'desc')
            ->get();

        $totalServices = $schedule->count() + $history->count();
        $completedCount = $history->where('status', 'Completed')->count();
        $scheduledCount = $schedule->where('status', 'Scheduled')->count();
        $overdueCount = $schedule->where('status', 'Overdue')->count();

        $nextService = $schedule->where('status', 'Scheduled')->first();
        $nextDate = $nextService ? Carbon::parse($nextService->service_date)->format('M d, Y') : ($asset->next_service_date ? Carbon::parse($asset->next_service_date)->format('M d, Y') : '—');
        $daysLeft = $nextService ? Carbon::now()->diffInDays(Carbon::parse($nextService->service_date), false) : ($asset->next_service_date ? Carbon::now()->diffInDays(Carbon::parse($asset->next_service_date), false) : 0);
        $serviceType = $nextService->service_type ?? ($asset->service_type ?? '—');

        if ($schedule->isEmpty() && $history->isEmpty()) {
            $schedule = collect([
                [
                    'id' => 101,
                    'asset_id' => $asset->id,
                    'record_type' => 'schedule',
                    'service_date' => '2025-06-15',
                    'service_type' => 'Routine Service',
                    'description' => 'Clean & lubricate, check tension',
                    'status' => 'Scheduled',
                    'assigned_to' => 'Technician',
                    'service_provider' => 'Certified Service Pro',
                ],
                [
                    'id' => 102,
                    'asset_id' => $asset->id,
                    'record_type' => 'schedule',
                    'service_date' => '2025-09-15',
                    'service_type' => 'Calibration',
                    'description' => 'Needle alignment & calibration',
                    'status' => 'Scheduled',
                    'assigned_to' => 'Technician',
                    'service_provider' => 'Certified Service Pro',
                ],
                [
                    'id' => 103,
                    'asset_id' => $asset->id,
                    'record_type' => 'schedule',
                    'service_date' => '2025-12-15',
                    'service_type' => 'Full Service',
                    'description' => 'Complete system inspection',
                    'status' => 'Scheduled',
                    'assigned_to' => 'Technician',
                    'service_provider' => 'Certified Service Pro',
                ],
                [
                    'id' => 104,
                    'asset_id' => $asset->id,
                    'record_type' => 'schedule',
                    'service_date' => '2026-03-15',
                    'service_type' => 'Routine Service',
                    'description' => 'Clean & lubricate, check tension',
                    'status' => 'Scheduled',
                    'assigned_to' => 'Technician',
                    'service_provider' => 'Certified Service Pro',
                ],
            ]);

            $history = collect([
                [
                    'id' => 201,
                    'asset_id' => $asset->id,
                    'record_type' => 'history',
                    'service_date' => '2025-03-15',
                    'completed_at' => 'Mar 15, 2025',
                    'time_display' => '10:24 AM',
                    'service_type' => 'Routine Service',
                    'description' => 'Cleaned, lubricated, checked tension and updated firmware.',
                    'performed_by' => 'Technician',
                    'status' => 'Completed',
                ],
                [
                    'id' => 202,
                    'asset_id' => $asset->id,
                    'record_type' => 'history',
                    'service_date' => '2024-12-10',
                    'completed_at' => 'Dec 10, 2024',
                    'time_display' => '02:15 PM',
                    'service_type' => 'Part Replacement',
                    'description' => 'Replaced needle bar and timing belt.',
                    'performed_by' => 'Technician',
                    'status' => 'Completed',
                ],
                [
                    'id' => 203,
                    'asset_id' => $asset->id,
                    'record_type' => 'history',
                    'service_date' => '2024-09-12',
                    'completed_at' => 'Sep 12, 2024',
                    'time_display' => '09:40 AM',
                    'service_type' => 'Calibration',
                    'description' => 'Full calibration and alignment.',
                    'performed_by' => 'Technician',
                    'status' => 'Completed',
                ],
                [
                    'id' => 204,
                    'asset_id' => $asset->id,
                    'record_type' => 'history',
                    'service_date' => '2024-06-05',
                    'completed_at' => 'Jun 05, 2024',
                    'time_display' => '11:20 AM',
                    'service_type' => 'Inspection',
                    'description' => 'General inspection, no issues found.',
                    'performed_by' => 'Technician',
                    'status' => 'Completed',
                ],
            ]);

            $totalServices = 5;
            $completedCount = 3;
            $scheduledCount = 1;
            $overdueCount = 1;
            $nextDate = 'Jun 15, 2025';
            $daysLeft = 15;
            $serviceType = 'Routine Service';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'stats' => [
                    'total_services' => $totalServices,
                    'completed' => $completedCount,
                    'scheduled' => $scheduledCount,
                    'overdue' => $overdueCount,
                    'next_date' => $nextDate,
                    'days_left' => max(0, (int)$daysLeft),
                    'service_type' => $serviceType,
                ],
                'schedule' => $schedule,
                'history' => $history,
            ]
        ]);
    }

    public function storeMaintenance(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'service_type' => 'required|string',
            'description' => 'nullable|string',
            'service_date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'next_service_date' => 'nullable|date',
            'record_type' => 'nullable|string', // 'schedule' or 'history'
            'status' => 'nullable|string',
            'assigned_to' => 'nullable|string',
            'cost' => 'nullable|numeric',
            'parts_cost' => 'nullable|numeric',
            'labor_cost' => 'nullable|numeric',
            'travel_cost' => 'nullable|numeric',
            'other_cost' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['asset_id'] = $asset->id;
        $recordType = $data['record_type'] ?? 'schedule';
        $data['record_type'] = $recordType;

        // Set service date appropriately
        if (empty($data['service_date'])) {
            $data['service_date'] = $data['start_date'] ?? date('Y-m-d');
        }

        if ($recordType === 'history') {
            $data['status'] = $data['status'] ?? 'Completed';
            $data['completed_at'] = Carbon::now();
            $parts = (float) ($data['parts_cost'] ?? 0);
            $labor = (float) ($data['labor_cost'] ?? 0);
            $travel = (float) ($data['travel_cost'] ?? 0);
            $other = (float) ($data['other_cost'] ?? 0);
            $totalCost = $parts + $labor + $travel + $other;
            if ($totalCost > 0 && empty($data['cost'])) {
                $data['cost'] = $totalCost;
            }
        } else {
            $data['status'] = $data['status'] ?? 'Scheduled';
        }

        $record = AssetMaintenanceRecord::create($data);

        // Update asset next maintenance date if provided
        if (!empty($data['next_service_date'])) {
            $asset->update(['next_maintenance_date' => $data['next_service_date']]);
        }

        // Audit Log
        if ($recordType === 'history') {
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'maintenance_completed',
                'title' => 'Maintenance Logged',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => 'Under Maintenance',
                'after_value' => 'Completed',
                'location' => $asset->location ?? 'Brooklyn, NY',
                'reference_number' => $record->invoice_number ?? ('LOG-' . date('Y') . '-' . str_pad($record->id, 3, '0', STR_PAD_LEFT)),
                'description' => "Maintenance completed: {$record->maintenance_title} ({$record->service_type}) - \${$record->cost}.",
                'performed_by' => $record->technician_name ?? 'Maintenance Team',
            ]);
        } else {
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'maintenance_scheduled',
                'title' => 'Maintenance Scheduled',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => '—',
                'after_value' => Carbon::parse($record->service_date)->format('M d, Y'),
                'location' => $asset->location ?? 'Brooklyn, NY',
                'reference_number' => 'SCH-' . date('Y') . '-' . str_pad($record->id, 3, '0', STR_PAD_LEFT),
                'description' => "Maintenance scheduled: {$record->maintenance_title} ({$record->service_type}) for {$asset->name}.",
                'performed_by' => 'Maintenance Team',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $recordType === 'history' ? 'Maintenance logged successfully' : 'Maintenance scheduled successfully',
            'data' => $record,
        ], 201);
    }

    /**
     * -------------------------------------------------------------
     * TAB 3: FINANCIAL HISTORY & BREAKDOWN
     * -------------------------------------------------------------
     */
    public function financial(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $query = AssetFinancialRecord::where('asset_id', $asset->id);

        if ($request->filled('type') && $request->type !== 'All Types') {
            $query->where('expense_type', $request->type);
        }

        $records = $query->orderBy('expense_date', 'asc')->get();

        // Totals
        $allRecords = AssetFinancialRecord::where('asset_id', $asset->id)->get();
        $purchaseCost = (float) $allRecords->where('expense_type', 'Purchase')->sum('amount');
        if ($purchaseCost <= 0 && !empty($asset->purchase_cost)) {
            $purchaseCost = (float) $asset->purchase_cost;
        }

        $laborTotal = (float) $allRecords->where('expense_type', 'Labor')->sum('amount');
        $partsTotal = (float) $allRecords->where('expense_type', 'Parts')->sum('amount');
        $travelTotal = (float) $allRecords->where('expense_type', 'Travel')->sum('amount');
        $otherTotal = (float) $allRecords->where('expense_type', 'Other')->sum('amount');

        $totalMaintenance = $laborTotal + $partsTotal + $travelTotal + $otherTotal;
        $totalAssetCost = $purchaseCost + $totalMaintenance;

        $laborPct = $totalMaintenance > 0 ? round(($laborTotal / $totalMaintenance) * 100) . '%' : '0%';
        $partsPct = $totalMaintenance > 0 ? round(($partsTotal / $totalMaintenance) * 100) . '%' : '0%';
        $travelPct = $totalMaintenance > 0 ? round(($travelTotal / $totalMaintenance) * 100) . '%' : '0%';
        $otherPct = $totalMaintenance > 0 ? round(($otherTotal / $totalMaintenance) * 100) . '%' : '0%';

        return response()->json([
            'status' => 'success',
            'data' => [
                'purchase_cost' => $purchaseCost,
                'total_maintenance_cost' => $totalMaintenance,
                'total_asset_cost' => $totalAssetCost,
                'breakdown' => [
                    'labor' => ['amount' => $laborTotal, 'percentage' => $laborPct],
                    'parts' => ['amount' => $partsTotal, 'percentage' => $partsPct],
                    'travel' => ['amount' => $travelTotal, 'percentage' => $travelPct],
                    'other' => ['amount' => $otherTotal, 'percentage' => $otherPct],
                ],
                'parts_history' => $allRecords->where('expense_type', 'Parts')->values(),
                'labor_history' => $allRecords->where('expense_type', 'Labor')->values(),
                'travel_history' => $allRecords->where('expense_type', 'Travel')->values(),
                'history' => $records,
            ]
        ]);
    }

    public function storeFinancial(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'expense_type' => 'required|string',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'nullable|date',
            'invoice_date' => 'nullable|date',
            'invoice_number' => 'nullable|string',
            'vendor_name' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'reference_number' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'attachment_name' => 'nullable|string',
            'attachment_size' => 'nullable|string',
            'receipt_url' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['asset_id'] = $asset->id;
        if (empty($data['expense_date'])) {
            $data['expense_date'] = $data['invoice_date'] ?? date('Y-m-d');
        }

        $record = AssetFinancialRecord::create($data);

        // Audit Log
        AssetActivityLog::create([
            'asset_id' => $asset->id,
            'action_type' => 'expense_added',
            'title' => 'Expense Added',
            'target_name' => "{$asset->name} ({$asset->asset_tag})",
            'before_value' => '—',
            'after_value' => '$' . number_format($record->amount, 2),
            'location' => $asset->location ?? 'Brooklyn, NY',
            'reference_number' => $record->invoice_number ?? ('EXP-' . date('Y') . '-' . str_pad($record->id, 3, '0', STR_PAD_LEFT)),
            'description' => "Expense logged: {$record->expense_type} - \${$record->amount} ({$record->description})",
            'performed_by' => 'Admin User',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Expense added successfully',
            'data' => $record,
        ], 201);
    }

    /**
     * -------------------------------------------------------------
     * TAB 4: ACTIVITY LOG WITH FILTERS
     * -------------------------------------------------------------
     */
    public function activity(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $query = AssetActivityLog::where(function ($q) use ($asset) {
            $q->where('asset_id', $asset->id)
              ->orWhere('target_name', 'LIKE', "%{$asset->asset_tag}%");
        });

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('performed_by', 'LIKE', "%{$search}%")
                  ->orWhere('reference_number', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('action_type') && $request->action_type !== 'All Activity Types') {
            $query->where('action_type', $request->action_type);
        }

        if ($request->filled('performed_by') && $request->performed_by !== 'All Users') {
            $query->where('performed_by', $request->performed_by);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $logs,
        ]);
    }

    /**
     * -------------------------------------------------------------
     * TAB 5: NOTES WITH ATTACHMENTS
     * -------------------------------------------------------------
     */
    public function notes(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $query = AssetNote::where('asset_id', $asset->id);

        if ($request->filled('category') && $request->category !== 'All Categories') {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('note_text', 'LIKE', "%{$search}%")
                  ->orWhere('author_name', 'LIKE', "%{$search}%");
            });
        }

        $notes = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $notes,
        ]);
    }

    public function storeNote(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'note_text' => 'required|string|max:2000',
            'category' => 'nullable|string|max:50',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $category = $request->input('category', 'General');
        $note = AssetNote::create([
            'asset_id' => $asset->id,
            'author_name' => 'Admin User',
            'author_initials' => 'AD',
            'author_avatar_bg' => 'bg-[#FF007A]',
            'category' => $category,
            'note_text' => trim($request->input('note_text')),
            'attachments' => $request->input('attachments', []),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Note saved successfully',
            'data' => $note,
        ], 201);
    }

    public function destroyNote($id, $noteId)
    {
        $note = AssetNote::where('asset_id', $id)->where('id', $noteId)->first();
        if ($note) {
            $note->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Note deleted successfully',
        ]);
    }

    /**
     * -------------------------------------------------------------
     * TAB: DOCUMENTS (Exact design match for Asset Documents modal)
     * -------------------------------------------------------------
     */
    public function documents(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        // Category counts
        $allDocs = AssetDocument::where('asset_id', $asset->id)->get();
        $counts = [
            'all' => $allDocs->count(),
            'purchase' => $allDocs->where('category', 'purchase')->count(),
            'warranty' => $allDocs->where('category', 'warranty')->count(),
            'manual' => $allDocs->where('category', 'manual')->count(),
            'maintenance' => $allDocs->where('category', 'maintenance')->count(),
            'photo' => $allDocs->where('category', 'photo')->count(),
        ];

        $query = AssetDocument::where('asset_id', $asset->id);

        // Filter category
        if ($request->filled('category')) {
            $cat = strtolower(trim($request->category));
            if ($cat !== 'all' && $cat !== 'all documents') {
                if ($cat === 'purchase documents') $cat = 'purchase';
                if ($cat === 'manuals') $cat = 'manual';
                if ($cat === 'photos') $cat = 'photo';
                $query->where('category', $cat);
            }
        }

        // Filter file type
        if ($request->filled('type') && $request->type !== 'All Types' && $request->type !== 'all') {
            $query->where('file_type', strtolower($request->type));
        }

        // Search query
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('file_name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Sorting
        $sort = $request->input('sort', 'newest');
        if ($sort === 'oldest' || $sort === 'Oldest First') {
            $query->orderBy('document_date', 'asc');
        } elseif ($sort === 'name_asc' || $sort === 'Name (A-Z)') {
            $query->orderBy('title', 'asc');
        } elseif ($sort === 'name_desc' || $sort === 'Name (Z-A)') {
            $query->orderBy('title', 'desc');
        } else {
            // Newest First (default)
            $query->orderBy('document_date', 'desc')->orderBy('created_at', 'desc');
        }

        $documents = $query->get();

        return response()->json([
            'status' => 'success',
            'counts' => $counts,
            'data' => $documents,
        ]);
    }

    public function storeDocument(Request $request, $id)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'file_name' => 'required|string|max:255',
            'file_type' => 'nullable|string|max:50',
            'file_size' => 'nullable|string|max:50',
            'file_url' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:50',
            'document_date' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['asset_id'] = $asset->id;
        $data['file_type'] = strtolower($data['file_type'] ?? 'pdf');
        $data['category'] = strtolower($data['category'] ?? 'purchase');
        $data['document_date'] = $data['document_date'] ?? date('Y-m-d');

        $doc = AssetDocument::create($data);

        // Activity log
        AssetActivityLog::create([
            'asset_id' => $asset->id,
            'action_type' => 'document_uploaded',
            'title' => 'Document Uploaded',
            'target_name' => "{$asset->name} ({$asset->asset_tag})",
            'before_value' => '—',
            'after_value' => $doc->title,
            'location' => $asset->location ?? 'Brooklyn, NY',
            'reference_number' => $doc->file_name,
            'description' => "Uploaded document: {$doc->title} ({$doc->file_name})",
            'performed_by' => 'Admin User',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Document uploaded successfully',
            'data' => $doc,
        ], 201);
    }

    public function destroyDocument($id, $docId)
    {
        $doc = AssetDocument::where('asset_id', $id)->where('id', $docId)->first();
        if ($doc) {
            $title = $doc->title;
            $doc->delete();

            AssetActivityLog::create([
                'asset_id' => $id,
                'action_type' => 'document_deleted',
                'title' => 'Document Deleted',
                'target_name' => "Asset Document",
                'before_value' => $title,
                'after_value' => 'Deleted',
                'location' => 'System',
                'reference_number' => 'DOC-' . $docId,
                'description' => "Deleted document {$title}",
                'performed_by' => 'Admin User',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Upload asset images or documents
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file->getClientOriginalName());
        $path = $file->storeAs('public/assets', $fileName);
        $url = '/storage/assets/' . $fileName;

        return response()->json([
            'status' => 'success',
            'data' => [
                'url' => $url,
                'name' => $file->getClientOriginalName(),
                'size' => round($file->getSize() / 1024, 1) . ' KB',
                'extension' => strtoupper($file->getClientOriginalExtension()),
            ]
        ]);
    }
}
