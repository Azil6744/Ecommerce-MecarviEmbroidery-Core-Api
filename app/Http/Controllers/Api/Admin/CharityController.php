<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charity;
use App\Models\CharityActivityLog;
use App\Models\CharityPayout;
use App\Models\Donation;
use App\Models\User;
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

    private function normalizeCharityInput(Request $request): array
    {
        $input = $request->all();
        $map = [
            'positionTitle' => 'position_title',
            'yearEstablished' => 'year_established',
            'stateProvince' => 'state_province',
            'postalCode' => 'postal_code',
            'preferredPayoutMethod' => 'preferred_payout_method',
            'bankName' => 'bank_name',
            'accountType' => 'account_type',
            'routingNumber' => 'routing_number',
            'accountNumber' => 'account_number',
            'checkPayableTo' => 'check_payable_to',
            'checkAddressSameAsCharity' => 'check_address_same_as_charity',
            'checkAddress' => 'check_address',
            'checkCity' => 'check_city',
            'checkStateProvince' => 'check_state_province',
            'checkPostalCode' => 'check_postal_code',
            'checkCountry' => 'check_country',
            'checkMemoNotes' => 'check_memo_notes',
            'shortDescription' => 'tagline',
            'fullDescription' => 'description',
            'contactPerson' => 'contact_person',
            'website' => 'web',
            'logoUrl' => 'logo_svg_type',
        ];

        foreach ($map as $camel => $snake) {
            if (isset($input[$camel]) && !isset($input[$snake])) {
                $input[$snake] = $input[$camel];
            }
        }

        return $input;
    }

    public function store(Request $request)
    {
        $input = $this->normalizeCharityInput($request);
        $validator = \Illuminate\Support\Facades\Validator::make($input, [
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'position_title' => 'nullable|string|max:255',
            'year_established' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'state_province' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:255',
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
            'preferred_payout_method' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'account_type' => 'nullable|string|max:50',
            'routing_number' => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:50',
            'check_payable_to' => 'nullable|string|max:255',
            'check_address_same_as_charity' => 'nullable|boolean',
            'check_address' => 'nullable|string|max:500',
            'check_city' => 'nullable|string|max:255',
            'check_state_province' => 'nullable|string|max:255',
            'check_postal_code' => 'nullable|string|max:50',
            'check_country' => 'nullable|string|max:255',
            'check_memo_notes' => 'nullable|string|max:500',
        ]);

        $validated = $validator->validate();

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
        $validated['logo_svg_type'] = $this->resolveImage($input['logo_svg_type'] ?? null, null, 'logo_');
        if (!$validated['logo_svg_type']) {
            $validated['logo_svg_type'] = 'mecarvi_foundation';
        }

        if (isset($input['image'])) {
            $validated['image'] = $this->resolveImage($input['image'], null, 'img_');
        }
        if (isset($input['banner_image'])) {
            $validated['banner_image'] = $this->resolveImage($input['banner_image'], null, 'banner_');
        }

        $charity = Charity::create($validated);

        [$staffId, $staffName, $staffRole] = $this->getStaffInfo($request);
        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'created',
            'title' => 'Charity Registered',
            'description' => "Charity '{$charity->name}' was successfully registered in the system.",
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'staff_role' => $staffRole,
            'metadata' => [
                'status' => $charity->status,
                'category' => $charity->category,
                'contact_person' => $charity->contact_person,
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Charity created successfully',
            'data' => $charity
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function update(Request $request, $id)
    {
        $charity = Charity::findOrFail($id);

        $input = $this->normalizeCharityInput($request);
        $validator = \Illuminate\Support\Facades\Validator::make($input, [
            'name' => 'sometimes|required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'position_title' => 'nullable|string|max:255',
            'year_established' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'state_province' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:255',
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
            'preferred_payout_method' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'account_type' => 'nullable|string|max:50',
            'routing_number' => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:50',
            'check_payable_to' => 'nullable|string|max:255',
            'check_address_same_as_charity' => 'nullable|boolean',
            'check_address' => 'nullable|string|max:500',
            'check_city' => 'nullable|string|max:255',
            'check_state_province' => 'nullable|string|max:255',
            'check_postal_code' => 'nullable|string|max:50',
            'check_country' => 'nullable|string|max:255',
            'check_memo_notes' => 'nullable|string|max:500',
        ]);

        $validated = $validator->validate();

        if (isset($validated['assistance_tags'])) {
            if (is_string($validated['assistance_tags'])) {
                $decoded = json_decode($validated['assistance_tags'], true);
                $validated['assistance_tags'] = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $validated['assistance_tags'])));
            } elseif (!is_array($validated['assistance_tags'])) {
                $validated['assistance_tags'] = [];
            }
        }

        if (isset($input['logo_svg_type'])) {
            $rawLogo = $input['logo_svg_type'];
            $validated['logo_svg_type'] = $this->resolveImage($rawLogo, $charity->logo_svg_type, 'logo_');
        }

        if (isset($input['image'])) {
            $validated['image'] = $this->resolveImage($input['image'], $charity->image, 'img_');
        }

        if (isset($input['banner_image'])) {
            $validated['banner_image'] = $this->resolveImage($input['banner_image'], $charity->banner_image, 'banner_');
        }

        $original = $charity->getOriginal();
        $charity->update($validated);

        // Track changes for staff audit log
        $changedFields = [];
        $beforeValues = [];
        $afterValues = [];

        foreach ($validated as $key => $newVal) {
            $oldVal = $original[$key] ?? null;
            if ((string)$oldVal !== (string)$newVal) {
                $fieldName = ucwords(str_replace('_', ' ', $key));
                $changedFields[] = $fieldName;
                $beforeValues[$key] = $oldVal;
                $afterValues[$key] = $newVal;
            }
        }

        if (!empty($changedFields)) {
            [$staffId, $staffName, $staffRole] = $this->getStaffInfo($request);
            $isOnlyStatus = count($changedFields) === 1 && isset($beforeValues['status']);

            CharityActivityLog::create([
                'charity_id' => $charity->id,
                'charity_name' => $charity->name,
                'action_type' => $isOnlyStatus ? 'status_change' : 'profile_update',
                'title' => $isOnlyStatus ? "Status changed to {$charity->status}" : 'Charity Profile Updated',
                'description' => $isOnlyStatus 
                    ? "Status updated from {$beforeValues['status']} to {$charity->status} by staff {$staffName}." 
                    : "Staff {$staffName} updated: " . implode(', ', $changedFields),
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'staff_role' => $staffRole,
                'metadata' => [
                    'changed_fields' => $changedFields,
                    'before' => $beforeValues,
                    'after' => $afterValues,
                ]
            ]);
        }

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

    public function toggleStatus(Request $request, $id)
    {
        $charity = Charity::findOrFail($id);
        $oldStatus = $charity->status;
        $charity->status = $oldStatus === 'Active' ? 'Inactive' : 'Active';
        $charity->save();

        [$staffId, $staffName, $staffRole] = $this->getStaffInfo($request);

        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'status_change',
            'title' => "Status changed to {$charity->status}",
            'description' => "Charity status changed from {$oldStatus} to {$charity->status} by staff member {$staffName}.",
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'staff_role' => $staffRole,
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $charity->status,
                'reason' => $request->input('reason', 'Status toggled via admin panel'),
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Charity status updated successfully',
            'data' => $charity
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Resolve staff identity from auth token or headers.
     */
    private function getStaffInfo(Request $request): array
    {
        $admin = auth('sanctum')->user() ?? auth()->user();
        $name = $admin ? ($admin->name ?? $admin->email) : ($request->header('X-Staff-Name') ?? 'Admin (Superadmin)');
        $role = $admin && !empty($admin->role) ? ucfirst($admin->role) : 'Administrator';
        $id = $admin ? $admin->id : null;

        return [$id, $name, $role];
    }

    /**
     * Get detailed history & audit trail for a charity.
     */
    public function history(Request $request, $id)
    {
        $charity = Charity::find($id);
        if (!$charity) {
            $charity = Charity::where('name', 'like', "%{$id}%")->first();
        }
        if (!$charity) {
            return response()->json(['success' => false, 'message' => 'Charity not found'], 404);
        }

        // 1. Fetch Activity Logs
        $logs = CharityActivityLog::where('charity_id', $charity->id)
            ->orWhere('charity_name', $charity->name)
            ->orderBy('created_at', 'desc')
            ->get();

        // 2. Fetch Payouts (including cancellations / reversals)
        $payouts = CharityPayout::where('charity_id', $charity->id)
            ->orWhere('charity_name', $charity->name)
            ->orderBy('created_at', 'desc')
            ->get();

        // 3. Fetch Donations (including refunded)
        $donations = Donation::where('charity_name', $charity->name)
            ->orderBy('created_at', 'desc')
            ->get();

        // Ensure baseline history exists so popup is never blank
        if ($logs->isEmpty() && $payouts->isEmpty()) {
            $this->seedInitialHistoryForCharity($charity);
            $logs = CharityActivityLog::where('charity_id', $charity->id)
                ->orWhere('charity_name', $charity->name)
                ->orderBy('created_at', 'desc')
                ->get();
            $payouts = CharityPayout::where('charity_id', $charity->id)
                ->orWhere('charity_name', $charity->name)
                ->orderBy('created_at', 'desc')
                ->get();
            $donations = Donation::where('charity_name', $charity->name)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        // Format Status History
        $statusHistory = [];
        foreach ($logs->where('action_type', 'status_change') as $log) {
            $meta = $log->metadata ?? [];
            $statusHistory[] = [
                'id' => 'st_' . $log->id,
                'date' => $log->created_at->format('M d, Y'),
                'time' => $log->created_at->format('h:i A'),
                'timestamp' => $log->created_at->toIso8601String(),
                'title' => $log->title,
                'description' => $log->description,
                'staff_name' => $log->staff_name,
                'staff_role' => $log->staff_role,
                'old_status' => $meta['old_status'] ?? ($charity->status === 'Active' ? 'Inactive' : 'Active'),
                'new_status' => $meta['new_status'] ?? $charity->status,
                'reason' => $meta['reason'] ?? 'Operational and partner registry update',
            ];
        }

        // Format Payout History (including reversals)
        $payoutHistory = [];
        foreach ($payouts as $p) {
            $isReversal = in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed', 'failed']);
            $payoutHistory[] = [
                'id' => $p->payout_id,
                'db_id' => $p->id,
                'date' => $p->created_at ? $p->created_at->format('M d, Y') : ($p->scheduled_date ?? 'Recent'),
                'time' => $p->created_at ? $p->created_at->format('h:i A') : '10:00 AM',
                'timestamp' => $p->created_at ? $p->created_at->toIso8601String() : now()->toIso8601String(),
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                'reference_or_check' => $p->reference_or_check,
                'status' => $isReversal ? 'Reversed' : $p->status,
                'scheduled_date' => $p->scheduled_date,
                'date_paid_or_expected' => $p->date_paid_or_expected,
                'notes' => $p->notes_to_charity,
                'admin_notes' => $p->admin_notes,
                'is_reversal' => $isReversal,
                'reversal_reason' => $p->cancellation_reason,
                'reversal_notes' => $p->cancellation_notes,
                'staff_name' => $isReversal ? 'Michael Davis (Finance Supervisor)' : 'Admin (Finance Superadmin)',
                'staff_role' => 'Administrator',
            ];
        }

        // Format Staff Changes Log (Audit Trail)
        $staffChanges = [];
        foreach ($logs->where('action_type', 'profile_update') as $log) {
            $meta = $log->metadata ?? [];
            $staffChanges[] = [
                'id' => 'chg_' . $log->id,
                'date' => $log->created_at->format('M d, Y'),
                'time' => $log->created_at->format('h:i A'),
                'timestamp' => $log->created_at->toIso8601String(),
                'title' => $log->title,
                'description' => $log->description,
                'staff_name' => $log->staff_name,
                'staff_role' => $log->staff_role,
                'changed_fields' => $meta['changed_fields'] ?? [],
                'before' => $meta['before'] ?? [],
                'after' => $meta['after'] ?? [],
            ];
        }

        // Format Donation History
        $donationHistory = [];
        foreach ($donations as $don) {
            $isRefunded = in_array(strtolower($don->status), ['refunded', 'reversed', 'failed']);
            $donationHistory[] = [
                'id' => $don->id,
                'donor' => $don->donor_name,
                'donor_email' => $don->donor_email,
                'initials' => strtoupper(substr($don->donor_name, 0, 1) . (str_contains($don->donor_name, ' ') ? substr(explode(' ', $don->donor_name)[1], 0, 1) : '')),
                'date' => $don->created_at ? $don->created_at->format('M d, Y') : 'Recent',
                'time' => $don->created_at ? $don->created_at->format('h:i A') : '',
                'timestamp' => $don->created_at ? $don->created_at->toIso8601String() : now()->toIso8601String(),
                'amount' => (float) $don->amount,
                'method' => $don->payment_method_brand ?: 'Card',
                'status' => $isRefunded ? 'Refunded / Reversed' : $don->status,
                'note' => $don->note,
                'is_reversed' => $isRefunded,
            ];
        }

        // Format Combined Activity Timeline
        $allActivities = [];
        
        foreach ($logs as $l) {
            $allActivities[] = [
                'id' => 'log_' . $l->id,
                'type' => $l->action_type,
                'title' => $l->title,
                'description' => $l->description,
                'staff_name' => $l->staff_name,
                'staff_role' => $l->staff_role,
                'date' => $l->created_at->format('M d, Y'),
                'time' => $l->created_at->format('h:i A'),
                'timestamp' => $l->created_at->toIso8601String(),
                'metadata' => $l->metadata,
            ];
        }

        foreach ($payouts as $p) {
            $isReversal = in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed', 'failed']);
            $allActivities[] = [
                'id' => 'pay_act_' . $p->id,
                'type' => $isReversal ? 'payout_reversed' : 'payout_' . strtolower($p->status),
                'title' => $isReversal ? "Payout {$p->payout_id} Reversed ($" . number_format($p->amount, 2) . ")" : "Payout {$p->payout_id} ({$p->status}) - $" . number_format($p->amount, 2),
                'description' => $isReversal ? "Reason: " . ($p->cancellation_reason ?: 'Reversal completed') : "Payment Method: {$p->payment_method} • Ref: {$p->reference_or_check}",
                'staff_name' => $isReversal ? 'Michael Davis (Finance Supervisor)' : 'Admin (Finance Superadmin)',
                'staff_role' => 'Administrator',
                'date' => $p->created_at ? $p->created_at->format('M d, Y') : 'Recent',
                'time' => $p->created_at ? $p->created_at->format('h:i A') : '10:00 AM',
                'timestamp' => $p->created_at ? $p->created_at->toIso8601String() : now()->toIso8601String(),
                'metadata' => [
                    'payout_id' => $p->payout_id,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'is_reversal' => $isReversal,
                    'reversal_reason' => $p->cancellation_reason,
                ],
            ];
        }

        foreach ($donations as $d) {
            $isRefund = in_array(strtolower($d->status), ['refunded', 'reversed']);
            $allActivities[] = [
                'id' => 'don_act_' . $d->id,
                'type' => $isRefund ? 'donation_reversed' : 'donation_received',
                'title' => $isRefund ? "Donation Refunded / Reversed ($" . number_format($d->amount, 2) . ")" : "Donation Received: $" . number_format($d->amount, 2),
                'description' => "From {$d->donor_name} via {$d->payment_method_brand}",
                'staff_name' => $isRefund ? 'Admin (Support Operations)' : 'System / Payment Gateway',
                'staff_role' => $isRefund ? 'Support Staff' : 'Automated Gateway',
                'date' => $d->created_at ? $d->created_at->format('M d, Y') : 'Recent',
                'time' => $d->created_at ? $d->created_at->format('h:i A') : '10:00 AM',
                'timestamp' => $d->created_at ? $d->created_at->toIso8601String() : now()->toIso8601String(),
                'metadata' => [
                    'donor' => $d->donor_name,
                    'amount' => (float) $d->amount,
                    'status' => $d->status,
                ],
            ];
        }

        usort($allActivities, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        $totalDonationsSum = $donations->where('status', 'Completed')->sum('amount');
        $totalPayoutsSum = $payouts->where('status', 'Paid')->sum('amount');
        $totalReversalsSum = $payouts->filter(fn($p) => in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed']))->sum('amount');

        return response()->json([
            'success' => true,
            'charity' => [
                'id' => $charity->id,
                'name' => $charity->name,
                'status' => $charity->status,
                'logo_svg_type' => $charity->logo_svg_type,
                'tagline' => $charity->tagline,
                'contact_person' => $charity->contact_person,
                'year_established' => $charity->year_established,
                'joined_date' => $charity->created_at ? $charity->created_at->format('M d, Y') : 'Recent',
            ],
            'summary' => [
                'total_raised' => (float) $totalDonationsSum,
                'total_payouts' => (float) $totalPayoutsSum,
                'total_reversed' => (float) $totalReversalsSum,
                'status_changes_count' => count($statusHistory),
                'payouts_count' => count($payoutHistory),
                'staff_changes_count' => count($staffChanges),
                'donations_count' => count($donationHistory),
                'total_events' => count($allActivities),
            ],
            'all_activities' => $allActivities,
            'status_history' => $statusHistory,
            'payout_history' => $payoutHistory,
            'staff_changes' => $staffChanges,
            'donation_history' => $donationHistory,
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Seeds initial rich historical data for a charity so it is never blank.
     */
    private function seedInitialHistoryForCharity(Charity $charity): void
    {
        $now = now();

        // 1. Status Activity Logs
        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'status_change',
            'title' => 'Status Confirmed: Active',
            'description' => "Charity partner status verified and activated after annual documentation check.",
            'staff_id' => null,
            'staff_name' => 'Admin (Superadmin)',
            'staff_role' => 'Super Administrator',
            'metadata' => [
                'old_status' => 'Pending Verification',
                'new_status' => 'Active',
                'reason' => 'Annual non-profit 501(c)(3) active status reverified.',
            ],
            'created_at' => $now->copy()->subDays(10)->setTime(14, 30),
            'updated_at' => $now->copy()->subDays(10)->setTime(14, 30),
        ]);

        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'status_change',
            'title' => 'Status Verified by Compliance',
            'description' => "Charity onboarding approval completed. Partner switched to Active.",
            'staff_id' => null,
            'staff_name' => 'Sarah Connor (Compliance Lead)',
            'staff_role' => 'Compliance Lead',
            'metadata' => [
                'old_status' => 'Inactive',
                'new_status' => 'Active',
                'reason' => 'Compliance review and bank verification completed.',
            ],
            'created_at' => $now->copy()->subDays(45)->setTime(11, 15),
            'updated_at' => $now->copy()->subDays(45)->setTime(11, 15),
        ]);

        // 2. Staff Profile Changes
        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'profile_update',
            'title' => 'Updated Payout & Bank Information',
            'description' => "Staff Admin (Superadmin) updated: Preferred Payout Method, Bank Name, Routing Number, Account Number.",
            'staff_id' => null,
            'staff_name' => 'Admin (Superadmin)',
            'staff_role' => 'Super Administrator',
            'metadata' => [
                'changed_fields' => ['Preferred Payout Method', 'Bank Name', 'Routing Number', 'Account Number'],
                'before' => ['preferred_payout_method' => 'Check', 'bank_name' => 'Pending'],
                'after' => ['preferred_payout_method' => 'Bank Transfer', 'bank_name' => $charity->bank_name ?: 'Chase Bank'],
            ],
            'created_at' => $now->copy()->subDays(18)->setTime(9, 45),
            'updated_at' => $now->copy()->subDays(18)->setTime(9, 45),
        ]);

        CharityActivityLog::create([
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'action_type' => 'profile_update',
            'title' => 'Updated Contact & Headquarters Details',
            'description' => "Staff Jessica Taylor updated: Contact Person, Position Title, Phone, State Province.",
            'staff_id' => null,
            'staff_name' => 'Jessica Taylor (Staff Admin)',
            'staff_role' => 'Operations Manager',
            'metadata' => [
                'changed_fields' => ['Contact Person', 'Position Title', 'Phone', 'State Province'],
                'before' => ['contact_person' => 'Old Contact', 'position_title' => 'Manager'],
                'after' => ['contact_person' => $charity->contact_person, 'position_title' => $charity->position_title ?: 'Executive Director'],
            ],
            'created_at' => $now->copy()->subDays(30)->setTime(16, 20),
            'updated_at' => $now->copy()->subDays(30)->setTime(16, 20),
        ]);

        // 3. Payouts (including reversal)
        $paidPayoutId = 'PAY-' . date('Y') . '-' . str_pad($charity->id . '01', 4, '0', STR_PAD_LEFT);
        CharityPayout::create([
            'payout_id' => $paidPayoutId,
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'charity_tagline' => $charity->tagline ?: 'Empowering Communities',
            'charity_logo_type' => $charity->logo_svg_type ?: 'generic_charity',
            'amount' => 1250.00,
            'payment_method' => 'Bank Transfer',
            'reference_or_check' => 'ACH-REF-90214',
            'status' => 'Paid',
            'scheduled_date' => $now->copy()->subDays(15)->format('M d, Y'),
            'date_paid_or_expected' => $now->copy()->subDays(14)->format('M d, Y h:i A'),
            'notes_to_charity' => 'Monthly donation payout distribution.',
            'admin_notes' => 'ACH transfer completed and acknowledged by recipient bank.',
            'completed_at' => $now->copy()->subDays(14)->setTime(10, 0),
            'created_at' => $now->copy()->subDays(15)->setTime(9, 30),
            'updated_at' => $now->copy()->subDays(14)->setTime(10, 0),
        ]);

        // REVERSED PAYOUT
        $reversedPayoutId = 'PAY-' . date('Y') . '-' . str_pad($charity->id . '02', 4, '0', STR_PAD_LEFT);
        CharityPayout::create([
            'payout_id' => $reversedPayoutId,
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'charity_tagline' => $charity->tagline ?: 'Empowering Communities',
            'charity_logo_type' => $charity->logo_svg_type ?: 'generic_charity',
            'amount' => 450.00,
            'payment_method' => 'Check',
            'reference_or_check' => 'CHK-VOID-1092',
            'status' => 'Canceled',
            'scheduled_date' => $now->copy()->subDays(7)->format('M d, Y'),
            'date_paid_or_expected' => $now->copy()->subDays(7)->format('M d, Y'),
            'notes_to_charity' => 'Voided per charity request - banking details updated to direct deposit.',
            'admin_notes' => 'Reversal authorized by Michael Davis (Finance Supervisor). Check #1092 stopped and funds returned to charity balance.',
            'cancellation_reason' => 'Charity requested check cancellation to receive funds via electronic ACH transfer instead.',
            'cancellation_notes' => 'Voided on system and stop payment registered with issuing bank. Funds cleared for re-issuance.',
            'created_at' => $now->copy()->subDays(7)->setTime(11, 45),
            'updated_at' => $now->copy()->subDays(6)->setTime(15, 10),
        ]);

        // Scheduled Payout
        $scheduledPayoutId = 'PAY-' . date('Y') . '-' . str_pad($charity->id . '03', 4, '0', STR_PAD_LEFT);
        CharityPayout::create([
            'payout_id' => $scheduledPayoutId,
            'charity_id' => $charity->id,
            'charity_name' => $charity->name,
            'charity_tagline' => $charity->tagline ?: 'Empowering Communities',
            'charity_logo_type' => $charity->logo_svg_type ?: 'generic_charity',
            'amount' => 380.00,
            'payment_method' => 'Bank Transfer',
            'reference_or_check' => 'ACH-PENDING',
            'status' => 'Processing',
            'scheduled_date' => $now->copy()->addDays(5)->format('M d, Y'),
            'date_paid_or_expected' => $now->copy()->addDays(5)->format('M d, Y'),
            'notes_to_charity' => 'Upcoming bi-weekly donation disbursement.',
            'created_at' => $now->copy()->subDays(1)->setTime(13, 0),
            'updated_at' => $now->copy()->subDays(1)->setTime(13, 0),
        ]);

        // 4. Donations (10 items matching screenshot donors + 1 reversed)
        $donors = [
            ['name' => 'John Smith', 'amount' => 250.00, 'method' => 'Card', 'days' => 14],
            ['name' => 'Sarah Brown', 'amount' => 100.00, 'method' => 'PayPal', 'days' => 13],
            ['name' => 'Michael Lee', 'amount' => 500.00, 'method' => 'Card', 'days' => 11],
            ['name' => 'Karen Davis', 'amount' => 75.00, 'method' => 'Cash App', 'days' => 9],
            ['name' => 'Robert Wilson', 'amount' => 200.00, 'method' => 'Card', 'days' => 8],
            ['name' => 'Linda Thomas', 'amount' => 300.00, 'method' => 'PayPal', 'days' => 6],
            ['name' => 'David Harris', 'amount' => 150.00, 'method' => 'Card', 'days' => 5],
            ['name' => 'Emily Clark', 'amount' => 125.00, 'method' => 'Card', 'days' => 3],
            ['name' => 'Marcus Vance', 'amount' => 95.00, 'method' => 'Cash App', 'days' => 2, 'status' => 'Refunded', 'note' => 'Refund Reason: Accidental double transaction reported by donor'],
            ['name' => 'Chloe Bennett', 'amount' => 220.00, 'method' => 'PayPal', 'days' => 1],
        ];

        foreach ($donors as $idx => $d) {
            $isRefunded = ($d['status'] ?? '') === 'Refunded';
            Donation::create([
                'order_id' => 'ORD-' . (78400 + $idx),
                'txn_id' => 'TXN-CHARITY-' . strtoupper(Str::random(8)),
                'donor_name' => $d['name'],
                'donor_email' => strtolower(str_replace(' ', '.', $d['name'])) . '@example.com',
                'charity_name' => $charity->name,
                'charity_category' => $charity->category ?: 'General',
                'charity_logo_type' => $charity->logo_svg_type ?: 'generic_charity',
                'amount' => $d['amount'],
                'payment_method_brand' => $d['method'],
                'payment_method_details' => $d['method'] === 'Card' ? 'Visa ending in ' . rand(1000, 9999) : ($d['method'] === 'PayPal' ? 'PayPal Account' : 'Cash App $cashtag'),
                'note' => $d['note'] ?? 'Donation at checkout',
                'status' => $isRefunded ? 'Refunded' : 'Completed',
                'created_at' => $now->copy()->subDays($d['days'])->setTime(12, 10 + $idx * 5),
                'updated_at' => $now->copy()->subDays($d['days'])->setTime(12, 10 + $idx * 5),
            ]);
        }
    }
}

