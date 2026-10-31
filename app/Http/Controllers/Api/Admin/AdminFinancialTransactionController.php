<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceAffiliate;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceMembership;
use App\Models\EcommerceOrder;
use App\Models\EcommerceQuotation;
use App\Models\EcommerceReturn;
use App\Models\EcommerceWalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AdminFinancialTransactionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = max(1, min((int) $request->get('per_page', 20), 100));
            $page = max(1, (int) $request->get('page', 1));
            $search = strtolower(trim((string) $request->get('search', '')));
            $type = strtolower(trim((string) $request->get('type', '')));
            $direction = strtolower(trim((string) $request->get('direction', '')));
            $status = strtolower(trim((string) $request->get('status', '')));

            $transactions = $this->ledger()
                ->filter(function ($item) use ($search, $type, $direction, $status) {
                    if ($type && strtolower($item['type']) !== $type) {
                        return false;
                    }

                    if ($direction && strtolower($item['direction']) !== $direction) {
                        return false;
                    }

                    if ($status && strtolower($item['status']) !== $status) {
                        return false;
                    }

                    if (!$search) {
                        return true;
                    }

                    $haystack = strtolower(implode(' ', [
                        $item['reference'],
                        $item['type'],
                        $item['customer_name'],
                        $item['customer_email'],
                        $item['description'],
                        $item['status'],
                    ]));

                    return str_contains($haystack, $search);
                })
                ->sortByDesc('date')
                ->values();

            $summary = [
                'incoming' => round($transactions->where('direction', 'incoming')->sum('amount'), 2),
                'outgoing' => round($transactions->where('direction', 'outgoing')->sum('amount'), 2),
                'neutral' => round($transactions->where('direction', 'neutral')->sum('amount'), 2),
                'net' => round(
                    $transactions->where('direction', 'incoming')->sum('amount') -
                    $transactions->where('direction', 'outgoing')->sum('amount'),
                    2
                ),
                'count' => $transactions->count(),
            ];

            $pageItems = $transactions->slice(($page - 1) * $perPage, $perPage)->values();
            $paginator = new LengthAwarePaginator(
                $pageItems,
                $transactions->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => $paginator,
                    'summary' => $summary,
                    'filters' => [
                        'types' => $transactions->pluck('type')->unique()->values(),
                        'statuses' => $transactions->pluck('status')->unique()->values(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch financial transactions.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function ledger(): Collection
    {
        return collect()
            ->merge($this->orderTransactions())
            ->merge($this->walletTransactions())
            ->merge($this->giftCardTransactions())
            ->merge($this->membershipTransactions())
            ->merge($this->quotationTransactions())
            ->merge($this->returnTransactions())
            ->merge($this->affiliateTransactions());
    }

    private function orderTransactions(): Collection
    {
        return EcommerceOrder::with(['user', 'transactions'])->get()->flatMap(function ($order) {
            $direction = $this->isNegativeStatus($order->status) ? 'neutral' : 'incoming';
            $customerName = $order->customer_name ?: optional($order->user)->name;
            $customerEmail = $order->customer_email ?: optional($order->user)->email;
            $date = $order->order_date ?: $order->created_at;

            if ($order->transactions && $order->transactions->isNotEmpty()) {
                return $order->transactions->map(function ($txn) use ($order, $direction, $customerName, $customerEmail, $date) {
                    $typeLabel = match (strtolower((string) $txn->type)) {
                        'tip' => 'Tip',
                        'donation' => 'Donation',
                        'shipping' => 'Shipping',
                        'turnaround' => 'Turnaround',
                        'packaging' => 'Packaging',
                        default => 'Order',
                    };
                    return $this->makeItem([
                        'id' => 'order-txn-' . $txn->id,
                        'source_id' => $order->id,
                        'reference' => $order->order_number,
                        'type' => $typeLabel,
                        'direction' => $direction,
                        'customer_name' => $customerName,
                        'customer_email' => $customerEmail,
                        'description' => $txn->label ?: ($typeLabel . ' payment'),
                        'amount' => $txn->amount,
                        'status' => $txn->status ?: $order->status,
                        'date' => $txn->created_at ?: $date,
                    ]);
                });
            }

            // Fallback for older orders created before the split ledger table
            $tip = round((float) ($order->tip_amount ?? 0), 2);
            $donation = round((float) ($order->donation_amount ?? 0), 2);
            $coreAmount = max(0, round((float) $order->total_amount - $tip - $donation, 2));

            $rows = [
                $this->makeItem([
                    'id' => 'order-' . $order->id,
                    'source_id' => $order->id,
                    'reference' => $order->order_number,
                    'type' => 'Order',
                    'direction' => $direction,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'description' => 'Order payment',
                    'amount' => $coreAmount,
                    'status' => $order->status,
                    'date' => $date,
                ]),
            ];

            if ($tip > 0) {
                $rows[] = $this->makeItem([
                    'id' => 'order-tip-' . $order->id,
                    'source_id' => $order->id,
                    'reference' => $order->order_number,
                    'type' => 'Tip',
                    'direction' => $direction,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'description' => 'Tip',
                    'amount' => $tip,
                    'status' => $order->status,
                    'date' => $date,
                ]);
            }

            if ($donation > 0) {
                $rows[] = $this->makeItem([
                    'id' => 'order-donation-' . $order->id,
                    'source_id' => $order->id,
                    'reference' => $order->order_number,
                    'type' => 'Donation',
                    'direction' => $direction,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'description' => 'Donation' . ($order->selected_charity ? ' - ' . $order->selected_charity : ''),
                    'amount' => $donation,
                    'status' => $order->status,
                    'date' => $date,
                ]);
            }

            return $rows;
        });
    }

    private function walletTransactions(): Collection
    {
        return EcommerceWalletTransaction::with('user')->get()->map(function ($transaction) {
            $walletType = strtolower((string) $transaction->type);

            return $this->makeItem([
                'id' => 'wallet-' . $transaction->id,
                'source_id' => $transaction->id,
                'reference' => $transaction->reference_id ?: 'WALLET-' . $transaction->id,
                'type' => 'Wallet',
                'direction' => $this->isOutgoingWalletType($walletType) ? 'outgoing' : 'incoming',
                'customer_name' => optional($transaction->user)->name,
                'customer_email' => optional($transaction->user)->email,
                'description' => $transaction->description ?: $transaction->type,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'date' => $transaction->created_at,
            ]);
        });
    }

    private function giftCardTransactions(): Collection
    {
        return EcommerceGiftCard::all()->map(function ($giftCard) {
            $redeemedAmount = max((float) $giftCard->initial_balance - (float) $giftCard->current_balance, 0);

            return $this->makeItem([
                'id' => 'gift-card-' . $giftCard->id,
                'source_id' => $giftCard->id,
                'reference' => $giftCard->code,
                'type' => 'Gift Card',
                'direction' => 'incoming',
                'customer_name' => $giftCard->recipient_name,
                'customer_email' => $giftCard->recipient_email,
                'description' => $redeemedAmount > 0
                    ? 'Gift card issued, redeemed ' . number_format($redeemedAmount, 2)
                    : 'Gift card issued',
                'amount' => $giftCard->initial_balance,
                'status' => $giftCard->status,
                'date' => $giftCard->created_at,
            ]);
        });
    }

    private function membershipTransactions(): Collection
    {
        return EcommerceMembership::with('user')->get()->map(function ($membership) {
            return $this->makeItem([
                'id' => 'membership-' . $membership->id,
                'source_id' => $membership->id,
                'reference' => 'MEM-' . $membership->id,
                'type' => 'Subscription',
                'direction' => strtolower($membership->status) === 'active' ? 'incoming' : 'neutral',
                'customer_name' => optional($membership->user)->name,
                'customer_email' => optional($membership->user)->email,
                'description' => trim($membership->plan_name . ' ' . $membership->billing_cycle),
                'amount' => $membership->price,
                'status' => $membership->status,
                'date' => $membership->created_at,
            ]);
        });
    }

    private function quotationTransactions(): Collection
    {
        return EcommerceQuotation::with('user')->get()->map(function ($quotation) {
            return $this->makeItem([
                'id' => 'quotation-' . $quotation->id,
                'source_id' => $quotation->id,
                'reference' => $quotation->quote_number,
                'type' => 'Quotation',
                'direction' => strtolower($quotation->status) === 'accepted' ? 'incoming' : 'neutral',
                'customer_name' => $quotation->customer_name ?: optional($quotation->user)->name,
                'customer_email' => $quotation->contact_email ?: optional($quotation->user)->email,
                'description' => $quotation->company_name ?: 'Estimated quote',
                'amount' => $quotation->total_estimated,
                'status' => $quotation->status,
                'date' => $quotation->created_at,
            ]);
        });
    }

    private function returnTransactions(): Collection
    {
        return EcommerceReturn::with('user')->get()->map(function ($return) {
            return $this->makeItem([
                'id' => 'return-' . $return->id,
                'source_id' => $return->id,
                'reference' => $return->return_number ?: $return->order_number,
                'type' => 'Refund',
                'direction' => in_array(strtolower($return->status), ['approved', 'completed', 'refunded'], true) ? 'outgoing' : 'neutral',
                'customer_name' => $return->customer_name ?: optional($return->user)->name,
                'customer_email' => optional($return->user)->email,
                'description' => $return->reason ?: 'Return refund',
                'amount' => $return->refund_amount,
                'status' => $return->status,
                'date' => $return->created_at,
            ]);
        });
    }

    private function affiliateTransactions(): Collection
    {
        return EcommerceAffiliate::with('user')->get()->map(function ($affiliate) {
            return $this->makeItem([
                'id' => 'affiliate-' . $affiliate->id,
                'source_id' => $affiliate->id,
                'reference' => $affiliate->affiliate_code,
                'type' => 'Affiliate',
                'direction' => 'outgoing',
                'customer_name' => optional($affiliate->user)->name,
                'customer_email' => optional($affiliate->user)->email,
                'description' => $affiliate->total_referrals . ' referrals earned',
                'amount' => $affiliate->total_earnings,
                'status' => $affiliate->status,
                'date' => $affiliate->updated_at ?: $affiliate->created_at,
            ]);
        });
    }

    private function makeItem(array $data): array
    {
        return [
            'id' => $data['id'],
            'source_id' => $data['source_id'],
            'reference' => $data['reference'] ?: '-',
            'type' => $data['type'],
            'direction' => $data['direction'],
            'customer_name' => $data['customer_name'] ?: 'Guest',
            'customer_email' => $data['customer_email'] ?: '-',
            'description' => $data['description'] ?: '-',
            'amount' => round((float) $data['amount'], 2),
            'status' => $data['status'] ?: 'Unknown',
            'date' => $this->formatDate($data['date']),
        ];
    }

    private function formatDate($date): string
    {
        if (!$date) {
            return Carbon::now()->toISOString();
        }

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->toISOString();
        }

        return Carbon::parse($date)->toISOString();
    }

    private function isNegativeStatus(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['cancelled', 'canceled', 'refunded', 'failed'], true);
    }

    private function isOutgoingWalletType(string $type): bool
    {
        return str_contains($type, 'debit')
            || str_contains($type, 'payment')
            || str_contains($type, 'withdraw')
            || str_contains($type, 'deduct');
    }
}
