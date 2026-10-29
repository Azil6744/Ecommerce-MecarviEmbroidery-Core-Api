<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VariableSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VariableSettingController extends Controller
{
    /**
     * Get variable settings, optionally filtered by group or type
     */
    public function index(Request $request)
    {
        if (VariableSetting::count() === 0) {
            $this->seedDefaults();
        }

        $query = VariableSetting::orderBy('sort_order', 'asc')->orderBy('id', 'asc');

        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $driver = \Illuminate\Support\Facades\DB::getDriverName();
            $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($search, $likeOp) {
                $q->where('name', $likeOp, "%{$search}%")
                  ->orWhere('parent_name', $likeOp, "%{$search}%")
                  ->orWhere('head_name', $likeOp, "%{$search}%")
                  ->orWhere('address', $likeOp, "%{$search}%")
                  ->orWhere('country', $likeOp, "%{$search}%")
                  ->orWhere('sub_department', $likeOp, "%{$search}%");
            });
        }

        $items = $query->get();

        // Also return counts grouped by type
        $allCounts = VariableSetting::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'counts' => $allCounts,
            'total' => $items->count(),
        ]);
    }

    /**
     * Get all real staff members for department head dropdown
     */
    public function staffOptions(Request $request)
    {
        $staff = \App\Models\User::where(function ($q) {
            $q->where('role', '!=', 'customer')
              ->orWhereNotNull('department')
              ->orWhereNotNull('job_title')
              ->orWhereNotNull('staff_id');
        })
        ->select('id', 'name', 'email', 'role', 'department', 'job_title', 'staff_id', 'avatar')
        ->orderBy('name', 'asc')
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $staff,
        ]);
    }

    /**
     * Upload an icon/image for variable settings
     */
    public function uploadIcon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'nullable|file|mimes:jpeg,png,jpg,svg,webp,gif,ico|max:5120',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,svg,webp,gif,ico|max:5120',
            'icon' => 'nullable|file|mimes:jpeg,png,jpg,svg,webp,gif,ico|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid file or file exceeds 5MB limit.',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file') ?? $request->file('image') ?? $request->file('icon');

        if (!$file) {
            // Check if base64 data string was submitted
            $base64 = $request->input('file') ?? $request->input('image') ?? $request->input('icon');
            if ($base64 && is_string($base64) && str_starts_with($base64, 'data:image/')) {
                $savedUrl = $this->saveBase64Image($base64);
                if ($savedUrl) {
                    return response()->json([
                        'status' => 'success',
                        'url' => $savedUrl,
                    ]);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => 'No file provided'
            ], 400);
        }

        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $extension;

        $dir = storage_path('app/public/variable_icons');
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file->storeAs('public/variable_icons', $fileName);
        $url = '/storage/variable_icons/' . $fileName;

        return response()->json([
            'status' => 'success',
            'url' => $url,
            'file_name' => $fileName,
        ]);
    }

    /**
     * Helper to safely save a base64 encoded image to storage
     */
    private function saveBase64Image(string $base64): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            return null;
        }

        $ext = strtolower($matches[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'])) {
            $ext = 'png';
        }

        $data = substr($base64, strpos($base64, ',') + 1);
        $data = base64_decode($data);
        if ($data === false) {
            return null;
        }

        $dir = storage_path('app/public/variable_icons');
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fileName = time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $ext;
        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        file_put_contents($path, $data);

        return '/storage/variable_icons/' . $fileName;
    }

    /**
     * Store a new variable setting
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string',
            'name' => 'required|string|max:255',
            'group' => 'nullable|string',
            'parent_name' => 'nullable|string|max:255',
            'head_name' => 'nullable|string|max:255',
            'icon' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'country' => 'nullable|string|max:255',
            'sub_department' => 'nullable|string|max:255',
            'time_zone' => 'nullable|string|max:255',
            'working_days' => 'nullable|array',
            'schedule_periods' => 'nullable|array',
            'hours_per_day' => 'nullable|numeric',
            'extra_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'status' => 'error',
                'message' => $firstError ?: 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();

        // If icon is a base64 string, auto-save to storage as an image file
        if (!empty($data['icon']) && is_string($data['icon']) && str_starts_with($data['icon'], 'data:image/')) {
            $savedUrl = $this->saveBase64Image($data['icon']);
            if ($savedUrl) {
                $data['icon'] = $savedUrl;
            }
        }

        // Infer group if not provided
        if (empty($data['group'])) {
            $type = $data['type'];
            if (in_array($type, ['departments', 'sub_departments', 'locations'])) {
                $data['group'] = 'general';
            } elseif (in_array($type, ['job_positions', 'work_shifts'])) {
                $data['group'] = 'hr';
            } elseif (in_array($type, ['payment_terms', 'payment_methods', 'expense_categories'])) {
                $data['group'] = 'finance';
            } elseif (in_array($type, ['campaign_types', 'marketing_channels', 'lead_sources'])) {
                $data['group'] = 'marketing';
            } else {
                $data['group'] = 'general';
            }
        }

        // Compute initials for head if given
        if (!empty($data['head_name']) && empty($data['head_initials'])) {
            $data['head_initials'] = $this->calculateInitials($data['head_name']);
        }

        $item = VariableSetting::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Variable setting created successfully',
            'data' => $item,
        ], 201);
    }

    /**
     * Update an existing variable setting
     */
    public function update(Request $request, $id)
    {
        $item = VariableSetting::find($id);

        if (!$item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Variable setting not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string',
            'group' => 'nullable|string',
            'parent_name' => 'nullable|string|max:255',
            'head_name' => 'nullable|string|max:255',
            'icon' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'country' => 'nullable|string|max:255',
            'sub_department' => 'nullable|string|max:255',
            'time_zone' => 'nullable|string|max:255',
            'working_days' => 'nullable|array',
            'schedule_periods' => 'nullable|array',
            'hours_per_day' => 'nullable|numeric',
            'extra_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return response()->json([
                'status' => 'error',
                'message' => $firstError ?: 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();

        // If icon is a base64 string, auto-save to storage as an image file
        if (!empty($data['icon']) && is_string($data['icon']) && str_starts_with($data['icon'], 'data:image/')) {
            $savedUrl = $this->saveBase64Image($data['icon']);
            if ($savedUrl) {
                $data['icon'] = $savedUrl;
            }
        }

        if (isset($data['head_name']) && !empty($data['head_name'])) {
            $data['head_initials'] = $this->calculateInitials($data['head_name']);
        }

        $item->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Variable setting updated successfully',
            'data' => $item,
        ]);
    }

    /**
     * Delete a variable setting
     */
    public function destroy($id)
    {
        $item = VariableSetting::find($id);

        if (!$item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Variable setting not found',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Variable setting deleted successfully',
        ]);
    }

    /**
     * Helper to compute initials from full name
     */
    private function calculateInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach ($parts as $p) {
            if (!empty($p)) {
                $initials .= strtoupper($p[0]);
                if (strlen($initials) >= 2) break;
            }
        }
        return $initials ?: strtoupper(substr($name, 0, 2));
    }

    /**
     * Seed initial default settings based on user designs
     */
    public function seedDefaults(): void
    {
        $defaults = [
            // General -> Departments
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Administration',
                'head_name' => 'Subrina Vazquez',
                'head_initials' => 'SV',
                'icon' => 'users',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Finance',
                'head_name' => 'Michael Chen',
                'head_initials' => 'MC',
                'icon' => 'banknotes',
                'color' => 'green',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Human Resources',
                'head_name' => 'Emily Davis',
                'head_initials' => 'ED',
                'icon' => 'users-round',
                'color' => 'orange',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Sales & Marketing',
                'head_name' => 'Sarah Mitchell',
                'head_initials' => 'SM',
                'icon' => 'megaphone',
                'color' => 'purple',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Production',
                'head_name' => 'David Taylor',
                'head_initials' => 'DT',
                'icon' => 'settings',
                'color' => 'blue',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Information Technology',
                'head_name' => 'James Wilson',
                'head_initials' => 'JW',
                'icon' => 'monitor',
                'color' => 'cyan',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Operations',
                'head_name' => 'John Doe',
                'head_initials' => 'JD',
                'icon' => 'briefcase',
                'color' => 'red',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Procurement',
                'head_name' => 'Robert Martinez',
                'head_initials' => 'RM',
                'icon' => 'shopping-cart',
                'color' => 'amber',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Logistics',
                'head_name' => 'Jennifer Thomas',
                'head_initials' => 'JT',
                'icon' => 'truck',
                'color' => 'purple',
            ],
            [
                'group' => 'general',
                'type' => 'departments',
                'name' => 'Customer Service',
                'head_name' => 'Lisa Anderson',
                'head_initials' => 'LA',
                'icon' => 'heart-handshake',
                'color' => 'pink',
            ],

            // General -> Sub Departments
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Accounting',
                'parent_name' => 'Finance',
                'icon' => 'calculator',
                'color' => 'green',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Budgeting',
                'parent_name' => 'Finance',
                'icon' => 'pie-chart',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Payroll',
                'parent_name' => 'Finance',
                'icon' => 'credit-card',
                'color' => 'blue',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Recruitment',
                'parent_name' => 'Human Resources',
                'icon' => 'user-plus',
                'color' => 'orange',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Employee Relations',
                'parent_name' => 'Human Resources',
                'icon' => 'users',
                'color' => 'purple',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Training & Development',
                'parent_name' => 'Human Resources',
                'icon' => 'graduation-cap',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Digital Marketing',
                'parent_name' => 'Sales & Marketing',
                'icon' => 'megaphone',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Content Creation',
                'parent_name' => 'Sales & Marketing',
                'icon' => 'edit-3',
                'color' => 'amber',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Production Planning',
                'parent_name' => 'Production',
                'icon' => 'settings',
                'color' => 'blue',
            ],
            [
                'group' => 'general',
                'type' => 'sub_departments',
                'name' => 'Quality Control',
                'parent_name' => 'Production',
                'icon' => 'shield-check',
                'color' => 'pink',
            ],

            // General -> Locations
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Atlanta Office',
                'address' => '1230 Peachtree St NE, Atlanta, GA 30309',
                'country' => 'United States',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Gwinnett Branch',
                'address' => '3455 Satellite Blvd, Duluth, GA 30096',
                'country' => 'United States',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Smyrna Branch',
                'address' => '2800 Cumberland Blvd, Smyrna, GA 30080',
                'country' => 'United States',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Alpharetta Branch',
                'address' => '11800 Avalon Blvd, Alpharetta, GA 30009',
                'country' => 'United States',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'New York Office',
                'address' => '350 5th Ave, New York, NY 10118',
                'country' => 'United States',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Toronto Office',
                'address' => '181 Bay St, Toronto, ON M5J 2T3',
                'country' => 'Canada',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'London Office',
                'address' => '85 King William St, London, EC4N 7BL',
                'country' => 'United Kingdom',
                'color' => 'pink',
            ],
            [
                'group' => 'general',
                'type' => 'locations',
                'name' => 'Grenada Office',
                'address' => 'Melville St, St. George\'s, Grenada',
                'country' => 'Grenada',
                'color' => 'pink',
            ],

            // HR -> Job Positions
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'CEO',
                'parent_name' => 'Administration',
                'sub_department' => '-',
                'icon' => 'users',
                'color' => 'pink',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Chief Financial Officer (CFO)',
                'parent_name' => 'Finance',
                'sub_department' => '-',
                'icon' => 'landmark',
                'color' => 'blue',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'HR Manager',
                'parent_name' => 'Human Resources',
                'sub_department' => '-',
                'icon' => 'user-check',
                'color' => 'teal',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Marketing Manager',
                'parent_name' => 'Sales & Marketing',
                'sub_department' => '-',
                'icon' => 'megaphone',
                'color' => 'purple',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Production Manager',
                'parent_name' => 'Production',
                'sub_department' => '-',
                'icon' => 'shield',
                'color' => 'orange',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'IT Manager',
                'parent_name' => 'Information Technology',
                'sub_department' => '-',
                'icon' => 'monitor',
                'color' => 'cyan',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Operations Manager',
                'parent_name' => 'Operations',
                'sub_department' => '-',
                'icon' => 'briefcase',
                'color' => 'pink',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Procurement Officer',
                'parent_name' => 'Procurement',
                'sub_department' => '-',
                'icon' => 'shopping-cart',
                'color' => 'amber',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Customer Service Rep',
                'parent_name' => 'Customer Service',
                'sub_department' => '-',
                'icon' => 'headphones',
                'color' => 'orange',
            ],
            [
                'group' => 'hr',
                'type' => 'job_positions',
                'name' => 'Graphic Designer',
                'parent_name' => 'Sales & Marketing',
                'sub_department' => 'Creative',
                'icon' => 'pen-tool',
                'color' => 'purple',
            ],

            // HR -> Work Shifts
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Day Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '09:00 AM', 'end_time' => '12:00 PM'],
                    ['start_time' => '01:00 PM', 'end_time' => '05:00 PM'],
                ],
                'hours_per_day' => 7.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Morning Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '08:00 AM', 'end_time' => '12:00 PM'],
                ],
                'hours_per_day' => 4.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Afternoon Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '01:00 PM', 'end_time' => '05:00 PM'],
                ],
                'hours_per_day' => 4.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Night Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '09:00 PM', 'end_time' => '05:00 AM'],
                ],
                'hours_per_day' => 8.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Weekend Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Sat', 'Sun'],
                'schedule_periods' => [
                    ['start_time' => '09:00 AM', 'end_time' => '05:00 PM'],
                ],
                'hours_per_day' => 8.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Flexible Shift',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '10:00 AM', 'end_time' => '02:00 PM'],
                    ['start_time' => '03:00 PM', 'end_time' => '07:00 PM'],
                ],
                'hours_per_day' => 8.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Custom Shift 1',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '04:00 PM', 'end_time' => '12:00 AM'],
                ],
                'hours_per_day' => 8.00,
            ],
            [
                'group' => 'hr',
                'type' => 'work_shifts',
                'name' => 'Part Time',
                'time_zone' => 'Eastern Time (ET)',
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'schedule_periods' => [
                    ['start_time' => '09:00 AM', 'end_time' => '01:00 PM'],
                ],
                'hours_per_day' => 4.00,
            ],

            // Finance Defaults
            [
                'group' => 'finance',
                'type' => 'payment_terms',
                'name' => 'Net 30 Days',
                'color' => 'blue',
            ],
            [
                'group' => 'finance',
                'type' => 'payment_terms',
                'name' => 'Due on Receipt',
                'color' => 'green',
            ],
            [
                'group' => 'finance',
                'type' => 'payment_terms',
                'name' => 'Net 15 Days',
                'color' => 'purple',
            ],
            [
                'group' => 'finance',
                'type' => 'payment_methods',
                'name' => 'Bank Wire / ACH',
                'color' => 'blue',
            ],
            [
                'group' => 'finance',
                'type' => 'payment_methods',
                'name' => 'Credit Card',
                'color' => 'pink',
            ],
            [
                'group' => 'finance',
                'type' => 'expense_categories',
                'name' => 'Office Supplies',
                'color' => 'amber',
            ],
            [
                'group' => 'finance',
                'type' => 'expense_categories',
                'name' => 'Equipment Maintenance',
                'color' => 'orange',
            ],

            // Marketing Defaults
            [
                'group' => 'marketing',
                'type' => 'campaign_types',
                'name' => 'Seasonal Promotion',
                'color' => 'pink',
            ],
            [
                'group' => 'marketing',
                'type' => 'campaign_types',
                'name' => 'Product Launch',
                'color' => 'purple',
            ],
            [
                'group' => 'marketing',
                'type' => 'marketing_channels',
                'name' => 'Google Ads',
                'color' => 'blue',
            ],
            [
                'group' => 'marketing',
                'type' => 'marketing_channels',
                'name' => 'Instagram / Social',
                'color' => 'pink',
            ],
            [
                'group' => 'marketing',
                'type' => 'lead_sources',
                'name' => 'Direct Referral',
                'color' => 'green',
            ],
            [
                'group' => 'marketing',
                'type' => 'lead_sources',
                'name' => 'Organic Search',
                'color' => 'cyan',
            ],
        ];

        foreach ($defaults as $i => $item) {
            $item['sort_order'] = $i + 1;
            VariableSetting::create($item);
        }
    }
}
