<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EcommerceWalletSetting extends Model
{
    protected $fillable = ['settings'];

    public static function defaults(): array
    {
        return [
            // General
            'enabled' => true,
            'wallet_name' => 'Mecarvi Cash',
            'min_balance_for_use' => 1.00,
            // Usage
            'allow_at_checkout' => true,
            'allow_partial_payment' => true,
            // Funding
            'allow_customer_add_funds' => true,
            'min_top_up' => 5.00,
            'max_top_up' => 1000.00,
            'payment_methods' => ['card', 'paypal', 'cashapp'],
            // Credit sources
            'source_affiliate' => true,
            'source_loyalty' => true,
            'source_store_credit' => true,
            'source_adjustments' => true,
            // Refunds
            'allow_refunds_to_wallet' => true,
            'auto_restore_wallet_payments' => true,
            'allow_partial_refunds' => true,
            // Expiration & inactivity
            'auto_disable_inactive' => true,
            'inactivity_period' => 12,
            'inactivity_unit' => 'months', // days|weeks|months|years
            'inactivity_action' => 'keep', // keep | refund
            // Admin permissions (comma separated role list)
            'perm_add_roles' => 'admin,manager,super_admin',
            'perm_adjust_roles' => 'manager,super_admin',
            'perm_credit_roles' => 'admin,manager,super_admin',
            'perm_disable_roles' => 'manager,super_admin',
            // Limits & security
            'max_wallet_balance' => 5000.00,
            'daily_add_limit' => 2000.00,
            'monthly_add_limit' => 10000.00,
            'decline_attempts_before_restrict' => 3,
        ];
    }

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
