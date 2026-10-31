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
        $charities = Charity::orderBy('created_at', 'desc')->get()->map(function ($charity) {
            $charityDonations = Donation::where('charity_name', $charity->name)
                ->orderBy('created_at', 'desc')
                ->get();

            $completedDonations = $charityDonations->where('status', 'Completed');
            $charity->total_donations = (float) $completedDonations->sum('amount');
            $charity->total_donors = $completedDonations
                ->map(fn ($d) => strtolower(trim($d->donor_email ?: $d->donor_name ?: ('don_' . $d->id))))
                ->unique()
                ->count();

            $charity->donations_history = $charityDonations->take(10)->map(function ($d) {
                $name = trim($d->donor_name ?: 'Anonymous');
                $parts = preg_split('/\s+/', $name);
                $initials = strtoupper(substr($parts[0] ?? 'A', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                return [
                    'id' => $d->id,
                    'donor' => $name,
                    'initials' => $initials,
                    'date' => $d->created_at ? $d->created_at->format('M d, Y') : 'Recent',
                    'amount' => (float) $d->amount,
                    'method' => $d->payment_method_brand ?: 'Card',
                    'status' => $d->status ?: 'Completed',
                ];
            })->values();

            return $charity;
        });
        
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
                'staff_name' => $log->staff_name ?: 'Administrator',
                'staff_role' => $log->staff_role ?: 'Administrator',
                'old_status' => $meta['old_status'] ?? ($charity->status === 'Active' ? 'Inactive' : 'Active'),
                'new_status' => $meta['new_status'] ?? $charity->status,
                'reason' => $meta['reason'] ?? 'Operational and partner registry update',
            ];
        }

        // Format Payout History (including reversals)
        $payoutHistory = [];
        foreach ($payouts as $p) {
            $isReversal = in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed', 'failed']);
            $matchingPayoutLog = $logs->first(function ($l) use ($p) {
                $meta = $l->metadata ?? [];
                return isset($meta['payout_id']) && $meta['payout_id'] === $p->payout_id;
            });
            $payoutStaffName = $matchingPayoutLog?->staff_name ?: 'Administrator';
            $payoutStaffRole = $matchingPayoutLog?->staff_role ?: 'Administrator';

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
                'staff_name' => $payoutStaffName,
                'staff_role' => $payoutStaffRole,
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
                'staff_name' => $log->staff_name ?: 'Administrator',
                'staff_role' => $log->staff_role ?: 'Administrator',
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
                'note' => $don->refund_reason ?: $don->note,
                'is_reversed' => $isRefunded,
            ];
        }

        // Format Combined Activity Timeline
        $allActivities = [];
        $loggedPayoutIds = [];
        $loggedDonationIds = [];
        
        foreach ($logs as $l) {
            $meta = $l->metadata ?? [];
            if (!empty($meta['payout_id'])) {
                $loggedPayoutIds[$meta['payout_id']] = true;
            }
            if (!empty($meta['donation_id'])) {
                $loggedDonationIds[$meta['donation_id']] = true;
            }
            $allActivities[] = [
                'id' => 'log_' . $l->id,
                'type' => $l->action_type,
                'title' => $l->title,
                'description' => $l->description,
                'staff_name' => $l->staff_name ?: 'Administrator',
                'staff_role' => $l->staff_role ?: 'Administrator',
                'date' => $l->created_at->format('M d, Y'),
                'time' => $l->created_at->format('h:i A'),
                'timestamp' => $l->created_at->toIso8601String(),
                'metadata' => $l->metadata,
            ];
        }

        foreach ($payouts as $p) {
            if (isset($loggedPayoutIds[$p->payout_id])) {
                continue;
            }
            $isReversal = in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed', 'failed']);
            $allActivities[] = [
                'id' => 'pay_act_' . $p->id,
                'type' => $isReversal ? 'payout_reversed' : 'payout_' . strtolower($p->status),
                'title' => $isReversal ? "Payout {$p->payout_id} Reversed ($" . number_format($p->amount, 2) . ")" : "Payout {$p->payout_id} ({$p->status}) - $" . number_format($p->amount, 2),
                'description' => $isReversal ? "Reason: " . ($p->cancellation_reason ?: 'Reversal completed') : "Payment Method: {$p->payment_method} • Ref: {$p->reference_or_check}",
                'staff_name' => 'Administrator',
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
            if ($isRefund && isset($loggedDonationIds[$d->id])) {
                continue;
            }
            $allActivities[] = [
                'id' => 'don_act_' . $d->id,
                'type' => $isRefund ? 'donation_reversed' : 'donation_received',
                'title' => $isRefund ? "Donation Refunded / Reversed ($" . number_format($d->amount, 2) . ")" : "Donation Received: $" . number_format($d->amount, 2),
                'description' => $isRefund
                    ? ("From {$d->donor_name} • Reason: " . ($d->refund_reason ?: 'Refunded'))
                    : "From {$d->donor_name} via {$d->payment_method_brand}",
                'staff_name' => $isRefund ? ($d->reversed_by ?: 'Administrator') : 'System / Payment Gateway',
                'staff_role' => $isRefund ? 'Administrator' : 'Automated Gateway',
                'date' => ($isRefund && $d->reversed_at) ? \Carbon\Carbon::parse($d->reversed_at)->format('M d, Y') : ($d->created_at ? $d->created_at->format('M d, Y') : 'Recent'),
                'time' => ($isRefund && $d->reversed_at) ? \Carbon\Carbon::parse($d->reversed_at)->format('h:i A') : ($d->created_at ? $d->created_at->format('h:i A') : '10:00 AM'),
                'timestamp' => ($isRefund && $d->reversed_at) ? \Carbon\Carbon::parse($d->reversed_at)->toIso8601String() : ($d->created_at ? $d->created_at->toIso8601String() : now()->toIso8601String()),
                'metadata' => [
                    'donation_id' => $d->id,
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
        $totalPayoutReversalsSum = $payouts->filter(fn($p) => in_array(strtolower($p->status), ['canceled', 'cancelled', 'reversed']))->sum('amount');
        $totalDonationReversalsSum = $donations->filter(fn($d) => in_array(strtolower($d->status), ['refunded', 'reversed']))->sum('amount');
        $totalReversalsSum = $totalPayoutReversalsSum + $totalDonationReversalsSum;

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
}

