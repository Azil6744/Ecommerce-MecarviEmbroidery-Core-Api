<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_number',
        'user_id',
        'order_id',
        'order_number',
        'customer_name',
        'reason',
        'return_items',
        'status',
        'return_status',
        'return_status_detail',
        'refund_amount',
        'items_subtotal',
        'estimated_refund_amount',
        'approved_amount',
        'approved_by',
        'admin_note',
        'refund_method',
        'payment_method_details',
        'currency',
        'return_address',
        'evidence_urls',
        'adjustments',
        'requested_at',
        'approved_at',
        'refunded_at',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_details',
        'resolution',
        'item_condition',
        'return_method',
        'customer_notes',
        'customer_response',
        'requested_info',
        'return_window_days',
        'return_window_deadline',
        'refund_origin',
        'claim_type',
        'who_pays_shipping',
        'rma_number',
        'received_at',
        'inspection_condition',
        'inspection_notes',
        'inspection_evidence',
        'decline_reason',
        'decline_details',
        'customer_explanation',
        'return_tracking_carrier',
        'return_tracking_number',
        'return_shipping_date',
        'return_estimated_delivery',
        'return_shipping_label_urls',
        'return_receipt_urls',
        'customer_declaration_confirmed',
        'return_tracking_status',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'items_subtotal' => 'decimal:2',
        'estimated_refund_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'return_window_days' => 'integer',
        'return_items' => 'array',
        'adjustments' => 'array',
        'evidence_urls' => 'array',
        'return_shipping_label_urls' => 'array',
        'return_receipt_urls' => 'array',
        'customer_declaration_confirmed' => 'boolean',
        'inspection_evidence' => 'array',
        'payment_method_details' => 'array',
        'requested_info' => 'array',
        'requested_at' => 'datetime',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'return_shipping_date' => 'date',
        'return_estimated_delivery' => 'date',
        'return_window_deadline' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    protected static function booted()
    {
        static::updated(function ($return) {
            // Trigger deduction when return is approved, completed, or refunded
            if ($return->wasChanged('status') && in_array($return->status, ['approved', 'completed', 'refunded'], true)) {
                try {
                    \DB::transaction(function () use ($return) {
                        $commission = \App\Models\EcommerceReferralCommission::where('order_id', $return->order_id)
                            ->where('status', '!=', 'cancelled')
                            ->first();

                        if ($commission) {
                            $order = $return->order;
                            $orderTotal = (float)($order ? ($order->subtotal ?: $order->total_amount) : $commission->order_amount);
                            $refundAmount = (float)$return->refund_amount;

                            // If refund amount is zero or covers the full order, treat as full refund
                            $isFullRefund = ($refundAmount <= 0) || ($refundAmount >= $orderTotal);
                            
                            $deduction = $isFullRefund 
                                ? (float)$commission->commission_amount 
                                : round((float)$commission->commission_amount * ($refundAmount / ($orderTotal ?: 1.00)), 2);

                            $deduction = min((float)$commission->commission_amount, $deduction);

                            if ($deduction > 0) {
                                if ($commission->status === 'pending') {
                                    if ($isFullRefund || $deduction >= (float)$commission->commission_amount) {
                                        $commission->update([
                                            'commission_amount' => 0.00,
                                            'status' => 'cancelled'
                                        ]);
                                    } else {
                                        $commission->update([
                                            'commission_amount' => round((float)$commission->commission_amount - $deduction, 2)
                                        ]);
                                    }
                                } elseif ($commission->status === 'completed') {
                                    $referrer = \App\Models\User::find($commission->referrer_id);
                                    if ($referrer) {
                                        \App\Services\WalletService::adjustWallet(
                                            $referrer->id,
                                            $deduction,
                                            'Affiliate Deduction',
                                            'Commission deduction for order #' . ($order ? $order->order_number : $return->order_number) . ' return',
                                            $return->order_id
                                        );

                                        // Decrement affiliate earnings
                                        $affiliate = $referrer->affiliate;
                                        if ($affiliate) {
                                            $affiliate->decrement('total_earnings', $deduction);
                                        }
                                    }

                                    if ($isFullRefund || $deduction >= (float)$commission->commission_amount) {
                                        $commission->update([
                                            'commission_amount' => 0.00,
                                            'status' => 'cancelled'
                                        ]);
                                    } else {
                                        $commission->update([
                                            'commission_amount' => round((float)$commission->commission_amount - $deduction, 2)
                                        ]);
                                    }
                                }
                            }
                        }
                    });
                } catch (\Exception $e) {
                    \Log::error('Referral commission deduction failed: ' . $e->getMessage());
                }
            }
        });
    }
}
