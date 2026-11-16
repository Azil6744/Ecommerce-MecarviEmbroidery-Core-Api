<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyAsset;
use App\Models\AssetActivityLog;
use App\Models\AssetMaintenanceRecord;
use App\Models\AssetFinancialRecord;
use App\Models\AssetNote;
use App\Models\AssetDocument;
use App\Models\AssetSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AssetController extends Controller
{
    /**
     * Normalize status values between UI labels and DB slugs
     */
    private function normalizeStatus(?string $status): string
    {
        if (empty($status)) {
            return 'in_use';
        }
        $lower = strtolower(trim(str_replace('_', ' ', $status)));
        if (in_array($lower, ['in use', 'active', 'reserved'])) {
            return 'in_use';
        }
        if (in_array($lower, ['under maintenance', 'in maintenance', 'maintenance', 'needs repair'])) {
            return 'under_maintenance';
        }
        if (in_array($lower, ['out of service', 'retired', 'disposed', 'damaged'])) {
            return 'out_of_service';
        }
        if ($lower === 'draft') {
            return 'draft';
        }
        return $status;
    }

    /**
     * Resolve default fallback category image based on category name
     */
    private function resolveCategoryImage(string $categoryName, ?string $customImageUrl = null): string
    {
        if (!empty($customImageUrl)) {
            return $customImageUrl;
        }
        $lower = strtolower($categoryName);
        if (str_contains($lower, 'embroidery') || str_contains($lower, 'production') || str_contains($lower, 'machine') || str_contains($lower, 'packaging')) {
            return '/images/assets/cat_embroidery.png';
        }
        if (str_contains($lower, 'computer') || str_contains($lower, 'it ') || $lower === 'it' || str_contains($lower, 'audio') || str_contains($lower, 'security')) {
            return '/images/assets/cat_computers.png';
        }
        if (str_contains($lower, 'office') || str_contains($lower, 'print')) {
            return '/images/assets/cat_office.png';
        }
        if (str_contains($lower, 'furniture') || str_contains($lower, 'fixture')) {
            return '/images/assets/cat_furniture.png';
        }
        return '/images/assets/cat_other.png';
    }
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
            $status = $request->status;
            if ($status === 'in_use' || strcasecmp($status, 'In Use') === 0) {
                $query->whereIn('status', ['in_use', 'In Use', 'Active', 'Reserved']);
            } elseif ($status === 'under_maintenance' || strcasecmp($status, 'Under Maintenance') === 0 || strcasecmp($status, 'In Maintenance') === 0) {
                $query->whereIn('status', ['under_maintenance', 'Under Maintenance', 'In Maintenance', 'Maintenance', 'Needs Repair']);
            } elseif ($status === 'out_of_service' || strcasecmp($status, 'Out of Service') === 0) {
                $query->whereIn('status', ['out_of_service', 'Out of Service', 'Retired', 'Disposed', 'Damaged']);
            } else {
                $query->where('status', $status);
            }
        }

        // Category filter
        if ($request->filled('category') && $request->category !== 'all' && $request->category !== 'All Assets') {
            $cat = $request->category;
            $query->where('category', $cat);
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
        $locationFilter = $request->input('location');
        $hasLocationFilter = !empty($locationFilter) && $locationFilter !== 'all' && $locationFilter !== 'All Locations';

        $baseQuery = function () use ($hasLocationFilter, $locationFilter) {
            $q = CompanyAsset::query();
            if ($hasLocationFilter) {
                $q->where('location', $locationFilter);
            }
            return $q;
        };

        $total = $baseQuery()->count();
        $inUse = $baseQuery()->whereIn('status', ['in_use', 'In Use', 'Active', 'Reserved'])->count();
        $underMaintenance = $baseQuery()->whereIn('status', ['under_maintenance', 'Under Maintenance', 'In Maintenance', 'Maintenance', 'Needs Repair'])->count();
        $outOfService = $baseQuery()->whereIn('status', ['out_of_service', 'Out of Service', 'Retired', 'Disposed', 'Damaged'])->count();

        // Fetch real categories from AssetSetting
        $settingCategories = AssetSetting::where('category_type', 'asset_categories')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Count assets per category (respecting location filter if set)
        $assetCountsByCat = $baseQuery()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as aggregate_count')
            ->groupBy('category')
            ->pluck('aggregate_count', 'category')
            ->toArray();

        // Also get all distinct categories across all assets so none are missed
        $allAssetCategories = CompanyAsset::whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category')
            ->toArray();

        $categories = [
            'All Assets' => $total,
        ];
        $categoryItemsMap = [];

        foreach ($settingCategories as $setting) {
            $catName = trim($setting->name);
            if ($catName === '') continue;
            $count = (int) ($assetCountsByCat[$catName] ?? 0);
            $customImg = $setting->image_url;
            $categories[$catName] = $count;
            $categoryItemsMap[$catName] = [
                'id' => $setting->id,
                'name' => $catName,
                'count' => $count,
                'image_url' => $this->resolveCategoryImage($catName, $customImg),
                'custom_image_url' => $customImg,
                'dot_color' => $setting->dot_color ?? 'blue',
                'has_custom_image' => !empty($customImg),
                'sort_priority' => ($count > 0 ? 1000 + $count : 0) + (!empty($customImg) ? 500 : 0) + (int) $setting->id,
            ];
        }

        foreach ($allAssetCategories as $catName) {
            $catName = trim($catName);
            if ($catName === '' || isset($categoryItemsMap[$catName])) continue;
            $count = (int) ($assetCountsByCat[$catName] ?? 0);
            $categories[$catName] = $count;
            $categoryItemsMap[$catName] = [
                'id' => 'cat_' . md5($catName),
                'name' => $catName,
                'count' => $count,
                'image_url' => $this->resolveCategoryImage($catName, null),
                'custom_image_url' => null,
                'dot_color' => 'pink',
                'has_custom_image' => false,
                'sort_priority' => ($count > 0 ? 1000 + $count : 500),
            ];
        }

        $categoryItems = array_values($categoryItemsMap);
        usort($categoryItems, function ($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }
            return $b['sort_priority'] <=> $a['sort_priority'];
        });

        // Locations list (combine locations from assets + locations from settings)
        $assetLocations = CompanyAsset::whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->pluck('location')
            ->toArray();

        $settingLocations = AssetSetting::where('category_type', 'locations')
            ->orderBy('sort_order', 'asc')
            ->pluck('name')
            ->toArray();

        $locations = array_values(array_unique(array_filter(array_merge($assetLocations, $settingLocations))));

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_assets' => $total,
                'in_use' => $inUse,
                'under_maintenance' => $underMaintenance,
                'out_of_service' => $outOfService,
                'categories' => $categories,
                'category_items' => $categoryItems,
                'locations' => $locations,
            ]
        ]);
    }

    /**
     * Get upcoming maintenance cards dynamically from database
     */
    public function upcomingMaintenance(Request $request)
    {
        $limit = (int) $request->input('limit', 8);
        if ($limit <= 0) $limit = 8;
        $locationFilter = $request->input('location');
        $hasLocationFilter = !empty($locationFilter) && $locationFilter !== 'all' && $locationFilter !== 'All Locations';

        // 1. Fetch upcoming scheduled maintenance records for existing assets
        $scheduledQuery = AssetMaintenanceRecord::with('asset')
            ->where('record_type', 'schedule')
            ->whereIn('status', ['Scheduled', 'Pending', 'In Progress', 'Overdue'])
            ->whereHas('asset', function ($q) use ($hasLocationFilter, $locationFilter) {
                if ($hasLocationFilter) {
                    $q->where('location', $locationFilter);
                }
            })
            ->whereNotNull('service_date')
            ->orderBy('service_date', 'asc');

        $scheduledRecords = $scheduledQuery->take($limit)->get();

        $includedAssetIds = $scheduledRecords->pluck('asset_id')->filter()->unique()->toArray();

        // 2. Also fetch active assets with next_service_date not already in scheduledRecords
        $assetsQuery = CompanyAsset::whereNotIn('id', $includedAssetIds)
            ->whereNotNull('next_service_date')
            ->whereNotIn('status', ['out_of_service', 'Out of Service', 'Disposed', 'Retired']);

        if ($hasLocationFilter) {
            $assetsQuery->where('location', $locationFilter);
        }

        $assetsWithService = $assetsQuery
            ->orderBy('next_service_date', 'asc')
            ->take(max(0, $limit - count($includedAssetIds)))
            ->get();

        $items = collect();

        foreach ($scheduledRecords as $record) {
            $asset = $record->asset;
            if (!$asset) continue;

            // Prefer next_service_date on schedule if service_date was set to start_date, or use service_date
            $effectiveDateStr = $record->service_date;
            if (!empty($record->next_service_date) && Carbon::parse($record->next_service_date)->gt(Carbon::parse($record->service_date))) {
                $effectiveDateStr = $record->next_service_date;
            }

            $serviceDate = Carbon::parse($effectiveDateStr);
            $now = Carbon::now()->startOfDay();
            $target = $serviceDate->copy()->startOfDay();
            $diffDays = (int) $now->diffInDays($target, false);

            if ($diffDays < 0) {
                $daysLabel = abs($diffDays) . 'd overdue';
            } elseif ($diffDays === 0) {
                $daysLabel = 'Today';
            } elseif ($diffDays === 1) {
                $daysLabel = '1 day';
            } else {
                $daysLabel = "{$diffDays} days";
            }

            $image = null;
            if (!empty($asset->images) && is_array($asset->images) && count($asset->images) > 0) {
                $image = $asset->images[0];
            }

            $items->push([
                'id' => $record->id,
                'asset_id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'asset_name' => $asset->name,
                'category' => $asset->category,
                'location' => $asset->location,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'status' => $record->status ?: ($diffDays < 0 ? 'Overdue' : 'Scheduled'),
                'assigned_to' => $record->assigned_to ?: ($asset->assigned_to ?: 'Technician'),
                'description' => $record->description,
                'service_type' => $record->service_type ?: ($record->maintenance_title ?: ($asset->service_type ?: 'Routine Maintenance')),
                'maintenance_title' => $record->maintenance_title ?: ($record->service_type ?: 'Scheduled Service'),
                'service_date' => $serviceDate->format('Y-m-d'),
                'date_month' => strtoupper($serviceDate->format('M')),
                'date_day' => $serviceDate->format('d'),
                'date_badge' => strtoupper($serviceDate->format('M d')),
                'days_left' => $diffDays,
                'days_label' => $daysLabel,
                'image' => $image,
            ]);
        }

        foreach ($assetsWithService as $asset) {
            $serviceDate = Carbon::parse($asset->next_service_date);
            $now = Carbon::now()->startOfDay();
            $target = $serviceDate->copy()->startOfDay();
            $diffDays = (int) $now->diffInDays($target, false);

            if ($diffDays < 0) {
                $daysLabel = abs($diffDays) . 'd overdue';
            } elseif ($diffDays === 0) {
                $daysLabel = 'Today';
            } elseif ($diffDays === 1) {
                $daysLabel = '1 day';
            } else {
                $daysLabel = "{$diffDays} days";
            }

            $image = null;
            if (!empty($asset->images) && is_array($asset->images) && count($asset->images) > 0) {
                $image = $asset->images[0];
            }

            $items->push([
                'id' => 'asset-' . $asset->id,
                'asset_id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'asset_name' => $asset->name,
                'category' => $asset->category,
                'location' => $asset->location,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'status' => $diffDays < 0 ? 'Overdue' : 'Scheduled',
                'assigned_to' => $asset->assigned_to ?: 'Technician',
                'description' => "Scheduled service ({$asset->service_frequency})",
                'service_type' => $asset->service_type ?: 'Routine Maintenance',
                'maintenance_title' => $asset->service_type ?: 'Routine Maintenance',
                'service_date' => $serviceDate->format('Y-m-d'),
                'date_month' => strtoupper($serviceDate->format('M')),
                'date_day' => $serviceDate->format('d'),
                'date_badge' => strtoupper($serviceDate->format('M d')),
                'days_left' => $diffDays,
                'days_label' => $daysLabel,
                'image' => $image,
            ]);
        }

        $sortedItems = $items->sortBy('days_left')->values()->take($limit);

        return response()->json([
            'status' => 'success',
            'data' => $sortedItems,
        ]);
    }


    /**
     * Get recent activity logs
     */
    public function recentActivity(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        if ($limit <= 0) $limit = 10;

        $query = AssetActivityLog::orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('target_name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('performed_by', 'LIKE', "%{$search}%")
                  ->orWhere('reference_number', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('action_type') && $request->action_type !== 'all') {
            $query->where('action_type', $request->action_type);
        }

        $logs = $query->take($limit)->get();

        $formatted = $logs->map(function ($log) {
            $diff = $log->created_at ? $log->created_at->diffForHumans() : 'Recently';
            return [
                'id' => $log->id,
                'asset_id' => $log->asset_id,
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
     * Clear all recent activity logs
     */
    public function clearRecentActivity()
    {
        AssetActivityLog::truncate();

        return response()->json([
            'status' => 'success',
            'message' => 'Activity logs cleared successfully',
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
        } else {
            $data['status'] = $this->normalizeStatus($data['status']);
        }

        if (empty($data['images']) || !is_array($data['images'])) {
            $data['images'] = [];
        }

        $asset = CompanyAsset::create($data);

        // Sync uploaded documents & receipt to AssetDocument table
        if (!empty($asset->receipt_url)) {
            $receiptFileName = basename($asset->receipt_url);
            $ext = strtolower(pathinfo($receiptFileName, PATHINFO_EXTENSION) ?: 'pdf');
            AssetDocument::create([
                'asset_id' => $asset->id,
                'title' => 'Purchase Receipt / Invoice',
                'file_name' => $receiptFileName,
                'file_type' => $ext,
                'file_size' => '150 KB',
                'file_url' => $asset->receipt_url,
                'category' => 'purchase',
                'document_date' => $asset->invoice_date ?? ($asset->purchase_date ?? date('Y-m-d')),
            ]);
        }

        if (!empty($asset->documents) && is_array($asset->documents)) {
            foreach ($asset->documents as $docItem) {
                if (is_array($docItem) && !empty($docItem['name'])) {
                    AssetDocument::create([
                        'asset_id' => $asset->id,
                        'title' => pathinfo($docItem['name'], PATHINFO_FILENAME) ?: $docItem['name'],
                        'file_name' => $docItem['name'],
                        'file_type' => strtolower($docItem['type'] ?? pathinfo($docItem['name'], PATHINFO_EXTENSION) ?: 'pdf'),
                        'file_size' => $docItem['size'] ?? '120 KB',
                        'file_url' => $docItem['url'] ?? '',
                        'category' => 'manual',
                        'document_date' => $asset->purchase_date ?? date('Y-m-d'),
                    ]);
                }
            }
        }

        if (!empty($asset->notes) && is_string($asset->notes) && trim($asset->notes) !== '') {
            AssetNote::create([
                'asset_id' => $asset->id,
                'author_name' => 'Admin User',
                'author_initials' => 'AD',
                'author_avatar_bg' => 'bg-[#FF007A]',
                'category' => 'General',
                'note_text' => trim($asset->notes),
                'attachments' => [],
            ]);
        }

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
        $updateData = $request->all();
        if (!empty($updateData['status'])) {
            $updateData['status'] = $this->normalizeStatus($updateData['status']);
        }
        $asset->update($updateData);

        // Sync maintenance schedule when next_service_date or has_maintenance_schedule is updated
        if ($request->has('has_maintenance_schedule') || $request->has('next_service_date')) {
            if (!empty($asset->has_maintenance_schedule) && !empty($asset->next_service_date)) {
                $existingSched = AssetMaintenanceRecord::where('asset_id', $asset->id)
                    ->where('record_type', 'schedule')
                    ->whereIn('status', ['Scheduled', 'Pending', 'Overdue'])
                    ->orderBy('id', 'asc')
                    ->first();

                if ($existingSched) {
                    $existingSched->update([
                        'maintenance_title' => $asset->service_type ?? $existingSched->maintenance_title,
                        'service_type' => $asset->service_type ?? $existingSched->service_type,
                        'service_date' => $asset->next_service_date,
                        'assigned_to' => $asset->assigned_to ?? $existingSched->assigned_to,
                    ]);
                } else {
                    AssetMaintenanceRecord::create([
                        'asset_id' => $asset->id,
                        'record_type' => 'schedule',
                        'maintenance_title' => $asset->service_type ?? 'Scheduled Service',
                        'service_type' => $asset->service_type ?? 'Preventive Maintenance',
                        'description' => "Scheduled service ({$asset->service_frequency})",
                        'status' => 'Scheduled',
                        'assigned_to' => $asset->assigned_to ?? 'Technician',
                        'service_date' => $asset->next_service_date,
                    ]);
                }
            }
        }

        // Log status change
        $newStatus = $asset->status;
        if ($request->filled('status') && $newStatus !== $oldStatus) {
            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'status_updated',
                'title' => 'Status Updated',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => ucwords(str_replace('_', ' ', $oldStatus)),
                'after_value' => ucwords(str_replace('_', ' ', $newStatus)),
                'location' => $asset->location ?? 'Main Facility',
                'reference_number' => 'STA-' . date('Y') . '-' . str_pad($asset->id, 3, '0', STR_PAD_LEFT),
                'description' => "Asset status changed from " . ucwords(str_replace('_', ' ', $oldStatus)) . " to " . ucwords(str_replace('_', ' ', $newStatus)) . ".",
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
            $asset->update([
                'next_service_date' => $data['next_service_date'],
                'service_type' => $data['service_type'] ?? $asset->service_type,
                'has_maintenance_schedule' => true,
            ]);
        } elseif ($recordType === 'schedule' && !empty($data['service_date'])) {
            $asset->update([
                'next_service_date' => $data['service_date'],
                'service_type' => $data['service_type'] ?? $asset->service_type,
                'has_maintenance_schedule' => true,
            ]);
        }

        // If completed maintenance history, also create financial cost records for breakdown
        if ($recordType === 'history') {
            $expenseDate = $record->service_date ?? date('Y-m-d');
            $vendor = $record->service_provider ?: ($record->technician_name ?: 'Maintenance Service');
            $invBase = $record->invoice_number ?: ('MNT-' . date('Y') . '-' . str_pad($record->id, 3, '0', STR_PAD_LEFT));
            $titleStr = $record->maintenance_title ?: ($record->service_type ?: 'Maintenance');

            $costMap = [
                'Parts' => (float) ($data['parts_cost'] ?? 0),
                'Labor' => (float) ($data['labor_cost'] ?? 0),
                'Travel' => (float) ($data['travel_cost'] ?? 0),
                'Other' => (float) ($data['other_cost'] ?? 0),
            ];
            foreach ($costMap as $expType => $amt) {
                if ($amt > 0) {
                    AssetFinancialRecord::create([
                        'asset_id' => $asset->id,
                        'expense_type' => $expType,
                        'description' => "{$titleStr} ({$expType})",
                        'invoice_number' => $invBase,
                        'vendor_name' => $vendor,
                        'amount' => $amt,
                        'expense_date' => $expenseDate,
                    ]);
                }
            }
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

    public function updateMaintenance(Request $request, $id, $maintId)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $record = AssetMaintenanceRecord::where('asset_id', $asset->id)
            ->where('id', $maintId)
            ->first();

        $data = $request->all();

        if (!$record) {
            $data['asset_id'] = $asset->id;
            $data['record_type'] = $data['record_type'] ?? 'schedule';
            if (empty($data['service_date'])) {
                $data['service_date'] = $data['start_date'] ?? date('Y-m-d');
            }
            $record = AssetMaintenanceRecord::create($data);
        } else {
            if (empty($data['service_date']) && !empty($data['start_date'])) {
                $data['service_date'] = $data['start_date'];
            }
            $record->update($data);
        }

        if (!empty($data['next_service_date'])) {
            $asset->update([
                'next_service_date' => $data['next_service_date'],
                'service_type' => $data['service_type'] ?? $asset->service_type,
                'has_maintenance_schedule' => true,
            ]);
        } elseif (!empty($data['service_date'])) {
            $asset->update([
                'next_service_date' => $data['service_date'],
                'service_type' => $data['service_type'] ?? $asset->service_type,
            ]);
        }

        AssetActivityLog::create([
            'asset_id' => $asset->id,
            'action_type' => 'maintenance_updated',
            'title' => 'Maintenance Schedule Updated',
            'target_name' => "{$asset->name} ({$asset->asset_tag})",
            'before_value' => 'Scheduled',
            'after_value' => Carbon::parse($record->service_date ?? now())->format('M d, Y'),
            'location' => $asset->location ?? 'Brooklyn, NY',
            'reference_number' => 'SCH-' . date('Y') . '-' . str_pad($record->id, 3, '0', STR_PAD_LEFT),
            'description' => "Maintenance schedule updated: " . ($record->maintenance_title ?: $record->service_type) . " for {$asset->name}.",
            'performed_by' => 'Maintenance Team',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Maintenance schedule updated successfully',
            'data' => $record,
        ]);
    }

    public function destroyMaintenance($id, $maintId)
    {
        $asset = is_numeric($id)
            ? CompanyAsset::find($id)
            : CompanyAsset::where('asset_tag', $id)->first();

        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => 'Asset not found'], 404);
        }

        $record = AssetMaintenanceRecord::where('asset_id', $asset->id)
            ->where('id', $maintId)
            ->first();

        if ($record) {
            $title = $record->maintenance_title ?: $record->service_type;
            $record->delete();

            AssetActivityLog::create([
                'asset_id' => $asset->id,
                'action_type' => 'maintenance_deleted',
                'title' => 'Maintenance Schedule Deleted',
                'target_name' => "{$asset->name} ({$asset->asset_tag})",
                'before_value' => 'Scheduled',
                'after_value' => 'Deleted',
                'location' => $asset->location ?? 'Brooklyn, NY',
                'reference_number' => 'SCH-' . $maintId,
                'description' => "Deleted maintenance schedule: {$title} for {$asset->name}.",
                'performed_by' => 'Maintenance Team',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Maintenance schedule deleted successfully',
        ]);
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
            $typeInput = trim($request->action_type);
            $normalizedType = strtolower(str_replace(' ', '_', $typeInput));
            $query->where(function ($q) use ($typeInput, $normalizedType) {
                $q->where('action_type', $typeInput)
                  ->orWhere('action_type', $normalizedType)
                  ->orWhere('title', 'LIKE', "%{$typeInput}%");
            });
        }

        if ($request->filled('performed_by') && $request->performed_by !== 'All Users') {
            $query->where('performed_by', $request->performed_by);
        }

        if ($request->filled('date_range') && $request->date_range !== 'All Time') {
            $range = $request->date_range;
            if ($range === 'Today') {
                $query->whereDate('created_at', Carbon::today());
            } elseif ($range === 'Last 7 Days') {
                $query->where('created_at', '>=', Carbon::now()->subDays(7)->startOfDay());
            } elseif ($range === 'Last 30 Days') {
                $query->where('created_at', '>=', Carbon::now()->subDays(30)->startOfDay());
            } elseif ($range === 'This Year') {
                $query->whereYear('created_at', Carbon::now()->year);
            } elseif ($range === 'Last Year') {
                $query->whereYear('created_at', Carbon::now()->subYear()->year);
            }
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
