<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'membership_discount_amount' => 'decimal:2',
        'membership_benefits_snapshot' => 'array',
        'membership_benefit_usage' => 'array',
        'tax_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'donation_amount' => 'decimal:2',
        'loyalty_points_earned' => 'integer',
        'loyalty_points_redeemed' => 'integer',
        'order_date' => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationship with user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with order items
    public function items()
    {
        return $this->hasMany(EcommerceOrderItem::class, 'order_id');
    }

    public function returns()
    {
        return $this->hasMany(EcommerceReturn::class, 'order_id');
    }

    // Generate order number
    public static function generateOrderNumber()
    {
        return 'ORD-' . date('Y') . '-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function proofs()
    {
        return $this->hasMany(EcommerceOrderProof::class, 'order_id');
    }

    public function verifications()
    {
        return $this->hasMany(EcommerceOrderVerification::class, 'order_id');
    }

    public function statusEvents()
    {
        return $this->hasMany(EcommerceOrderStatusEvent::class, 'order_id')->latest();
    }

    public function pickupLocation()
    {
        return $this->belongsTo(StorePickupLocation::class, 'pickup_location_id');
    }

    public function recordStatusEvent(string $status, string $label, ?string $note = null, ?int $userId = null): EcommerceOrderStatusEvent
    {
        return $this->statusEvents()->create([
            'user_id' => $userId ?: auth()->id(),
            'status' => $status,
            'label' => $label,
            'note' => $note,
        ]);
    }

    public function recordActivityAndNotify(string $eventKey, string $status, string $label, ?string $note = null, array $extraPayload = []): void
    {
        $this->recordStatusEvent($status, $label, $note);

        try {
            app(\App\Services\EmailNotificationService::class)->sendOrderEvent($eventKey, $this, array_merge([
                'reason' => $note ?: $label,
                'status_note' => $note,
            ], $extraPayload));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send order activity notification [{$eventKey}]: " . $e->getMessage());
        }
    }

    protected static function booted()
    {
        $processCommission = function ($order) {
            $settings = \App\Services\AffiliateCommissionService::settings();
            $eligible = $settings['commission_eligible'] ?? 'After Order Is Completed';
            $isPaid = strtolower((string) $order->payment_status) === 'paid';
            $isCompleted = in_array(strtolower((string) $order->order_status), ['completed', 'delivered'], true);

            $shouldProcess = false;
            if ($eligible === 'Immediately upon Order Placement') {
                $shouldProcess = true;
            } else {
                $shouldProcess = $isPaid || $isCompleted;
            }

            if (!$shouldProcess) {
                return;
            }
            try {
                $user = $order->user;
                if (!$user && $order->user_id) {
                    $user = \App\Models\User::find($order->user_id);
                }
                if (!$user) {
                    return;
                }

                \App\Services\AffiliateCommissionService::record(
                    $user,
                    \App\Services\AffiliateCommissionService::SOURCE_ORDER,
                    (string) $order->id,
                    (float) ($order->subtotal ?: $order->total_amount),
                    $order->id,
                    request()?->ip()
                );
            } catch (\Throwable $e) {
                \Log::error('Referral commission failed: ' . $e->getMessage());
            }
        };

        static::created(function ($order) use ($processCommission) {
            $processCommission($order);
        });

        static::updated(function ($order) use ($processCommission) {
            if ($order->wasChanged(['payment_status', 'order_status'])) {
                $processCommission($order);
            }

            // Cancelled / refunded orders must not earn commission (cancelled_orders_action).
            $cancelled = in_array(strtolower((string) $order->order_status), ['cancelled', 'canceled'], true);
            $refunded = strtolower((string) $order->payment_status) === 'refunded';
            if (($cancelled || $refunded) && $order->wasChanged(['order_status', 'payment_status'])) {
                try {
                    \App\Services\AffiliateCommissionService::voidForOrder(
                        $order->id,
                        $refunded ? 'Order refunded' : 'Order cancelled'
                    );
                } catch (\Throwable $e) {
                    \Log::error('Referral commission void failed: ' . $e->getMessage());
                }
            }
        });
    }
}
