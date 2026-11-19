<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceVoucherSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EcommerceVoucherSettingsController extends Controller
{
    public function show()
    {
        return response()->json(['success' => true, 'data' => EcommerceVoucherSetting::current()]);
    }

    public function update(Request $request)
    {
        $presets = ['admin,manager,super_admin', 'manager,super_admin', 'admin,super_admin', 'super_admin'];

        $data = $request->validate([
            'code_prefix' => ['required', 'string', 'max:6', 'regex:/^[A-Za-z0-9]+$/'],
            'code_format' => ['required', 'string', 'in:XXXXX-XXXXX-XX,XXXX-XXXXX-XX,XXXXXXXXXXXX'],
            'timezone' => 'required|string|max:64|timezone',
            'auto_expire' => 'required|boolean',
            'notify_before_expiry' => 'required|boolean',
            'notify_lead_days' => 'required|integer|in:1,3,7,14,30',
            'channel_online_checkout' => 'required|boolean',
            'channel_in_store_pos' => 'required|boolean',
            'max_per_order' => 'required|integer|min:0|max:10',
            'allow_partial_redemption' => 'required|boolean',
            'allow_multiple_orders' => 'required|boolean',
            'allow_split_payment' => 'required|boolean',
            'restore_on_cancel' => 'required|boolean',
            'cancel_remaining_on_void' => 'required|boolean',
            'perm_issue_enabled' => 'required|boolean',
            'perm_issue_roles' => ['required', Rule::in($presets)],
            'perm_void_enabled' => 'required|boolean',
            'perm_void_roles' => ['required', Rule::in($presets)],
            'perm_delete_enabled' => 'required|boolean',
            'perm_delete_roles' => ['required', Rule::in($presets)],
        ]);

        $data['code_prefix'] = strtoupper($data['code_prefix']);

        $row = EcommerceVoucherSetting::query()->first();
        if ($row) {
            $row->update(['settings' => json_encode($data)]);
        } else {
            EcommerceVoucherSetting::create(['settings' => json_encode($data)]);
        }

        return response()->json(['success' => true, 'data' => EcommerceVoucherSetting::current()]);
    }
}
