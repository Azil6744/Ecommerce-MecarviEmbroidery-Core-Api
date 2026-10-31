<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderTransaction as Txn;

/**
 * Splits the single gateway charge of an order into separate ledger transactions:
 * order (items + tax - discounts), shipping (one per destination address), packaging,
 * turnaround (once per order), tip and one per donation. The rows always add up to the order total.
 */
class OrderTransactionLedger
{
    /**
     * @param array $parts shipping, packaging, turnaround, tip (floats);
     *                     shipping_groups: [['label','amount','carrier','service','address'], ...] (optional);
     *                     donations: [['charity_name','amount','note'], ...];
     *                     gateway_reference (optional)
     */
    public function record(EcommerceOrder $order, array $parts): void
    {
        if ($order->transactions()->exists()) {
            return; // never double-record
        }

        $method = $order->payment_method;
        $ref = $parts['gateway_reference'] ?? null;
        $base = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_method' => $method,
            'gateway_reference' => $ref,
            'status' => 'completed',
        ];

        $rows = [];

        // Shipping: one row per destination address when the groups add up, otherwise a single row.
        $shipping = round((float) ($parts['shipping'] ?? 0), 2);
        if ($shipping > 0) {
            $groups = array_values(array_filter($parts['shipping_groups'] ?? [], fn($g) => (float) ($g['amount'] ?? 0) > 0));
            $groupSum = round(array_sum(array_map(fn($g) => (float) $g['amount'], $groups)), 2);
            if (count($groups) > 0 && abs($groupSum - $shipping) < 0.01) {
                foreach ($groups as $i => $g) {
                    $rows[] = [
                        'type' => Txn::TYPE_SHIPPING,
                        'label' => $g['label'] ?? ('Shipping - address ' . ($i + 1)),
                        'amount' => round((float) $g['amount'], 2),
                        'shipment_group' => 'group_' . ($i + 1),
                        'meta' => ['carrier' => $g['carrier'] ?? null, 'service' => $g['service'] ?? null, 'address' => $g['address'] ?? null],
                    ];
                }
            } else {
                $rows[] = ['type' => Txn::TYPE_SHIPPING, 'label' => 'Shipping / delivery', 'amount' => $shipping, 'shipment_group' => 'group_1'];
            }
        }

        $packaging = round((float) ($parts['packaging'] ?? 0), 2);
        if ($packaging > 0) {
            $rows[] = ['type' => Txn::TYPE_PACKAGING, 'label' => 'Packaging', 'amount' => $packaging];
        }

        $turnaround = round((float) ($parts['turnaround'] ?? 0), 2);
        if ($turnaround > 0) {
            $rows[] = ['type' => Txn::TYPE_TURNAROUND, 'label' => 'Turnaround fee (once per order)', 'amount' => $turnaround];
        }

        $tip = round((float) ($parts['tip'] ?? 0), 2);
        if ($tip > 0) {
            $rows[] = ['type' => Txn::TYPE_TIP, 'label' => 'Tip', 'amount' => $tip];
        }

        foreach (($parts['donations'] ?? []) as $d) {
            $amt = round((float) ($d['amount'] ?? 0), 2);
            if ($amt > 0) {
                $rows[] = [
                    'type' => Txn::TYPE_DONATION,
                    'label' => 'Donation - ' . ($d['charity_name'] ?? 'Charity'),
                    'amount' => $amt,
                    'charity_name' => $d['charity_name'] ?? null,
                    'meta' => ['note' => $d['note'] ?? null],
                ];
            }
        }

        // The order row is whatever remains of the charged total (items + tax - discounts), so the ledger always balances.
        $others = round(array_sum(array_column($rows, 'amount')), 2);
        $orderAmount = round(max(0, (float) $order->total_amount - $others), 2);
        array_unshift($rows, [
            'type' => Txn::TYPE_ORDER,
            'label' => 'Order (items, tax, discounts)',
            'amount' => $orderAmount,
            'meta' => [
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax_amount,
                'discount' => (float) $order->discount_amount,
            ],
        ]);

        foreach ($rows as $row) {
            Txn::create($base + $row);
        }
    }
}
