<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EcommerceVoucherSetting extends Model
{
    protected $fillable = ['settings'];

    public static function defaults(): array
    {
        return [
            'code_prefix' => 'ME',
            'code_format' => 'XXXXX-XXXXX-XX',
            'timezone' => 'America/New_York',
            'auto_expire' => true,
            'notify_before_expiry' => true,
            'notify_lead_days' => 7,
            'channel_online_checkout' => true,
            'channel_in_store_pos' => true,
            'max_per_order' => 1,
            'allow_partial_redemption' => true,
            'allow_multiple_orders' => true,
            'allow_split_payment' => true,
            'restore_on_cancel' => true,
            'cancel_remaining_on_void' => true,
            'perm_issue_enabled' => true,
            'perm_issue_roles' => 'admin,manager,super_admin',
            'perm_void_enabled' => true,
            'perm_void_roles' => 'admin,manager,super_admin',
            'perm_delete_enabled' => true,
            'perm_delete_roles' => 'super_admin',
        ];
    }

    /** Stored settings merged over defaults. Safe to call before the table exists. */
    public static function current(): array
    {
        $defaults = static::defaults();
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return $defaults;
            }
            $row = static::query()->first();
            $stored = $row ? json_decode($row->settings, true) : [];

            return array_merge($defaults, is_array($stored) ? $stored : []);
        } catch (\Throwable $e) {
            return $defaults;
        }
    }
}
