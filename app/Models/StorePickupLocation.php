<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorePickupLocation extends Model
{
    use HasFactory;

    protected $table = 'store_pickup_locations';

    protected $fillable = [
        'name',
        'code',
        'store_type',
        'timezone',
        'address',
        'phone',
        'notes',
        'short_description',
        'image_path',
        'status',
        'is_pickup_enabled',
        'pickup_preparation_time',
        'pickup_preparation_unit',
        'max_pickup_radius',
        'latitude',
        'longitude',
        'weekly_schedule',
        'special_hours',
        'max_future_days',
        'notif_order_ready',
        'notif_order_picked_up',
        'notif_new_pickup_alert',
        'cust_notif_email',
        'cust_notif_sms',
        'staff_notif_email',
        'staff_notif_sms',
        'id_verification_required',
        'allow_pickup_by_others',
        'pickup_code_required',
        'pickup_code_expiry',
        'order_hold_duration',
        'late_pickup_action',
        'refund_policy',
        'allow_partial_pickup',
        'show_pickup_instructions',
        'additional_pickup_instructions',
        'pickup_settings',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_pickup_enabled' => 'boolean',
        'pickup_preparation_time' => 'integer',
        'max_pickup_radius' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'weekly_schedule' => 'array',
        'special_hours' => 'array',
        'notif_order_ready' => 'boolean',
        'notif_order_picked_up' => 'boolean',
        'notif_new_pickup_alert' => 'boolean',
        'cust_notif_email' => 'boolean',
        'cust_notif_sms' => 'boolean',
        'staff_notif_email' => 'boolean',
        'staff_notif_sms' => 'boolean',
        'id_verification_required' => 'boolean',
        'allow_pickup_by_others' => 'boolean',
        'pickup_code_required' => 'boolean',
        'allow_partial_pickup' => 'boolean',
        'show_pickup_instructions' => 'boolean',
        'pickup_settings' => 'array',
    ];

    /**
     * Scope to get only active locations.
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Scope to get only pickup enabled locations.
     */
    public function scopePickupEnabled($query)
    {
        return $query->where('is_pickup_enabled', true);
    }
}
