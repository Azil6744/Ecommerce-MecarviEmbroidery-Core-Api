<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceDispute;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\EcommerceMembership;
use App\Models\EcommerceTicket;
use App\Models\EcommerceTicketReply;
use App\Models\EcommerceCustomerVerification;
use App\Models\EcommerceCustomerFile;
use App\Models\ProductReport;
use App\Models\EcommerceCoupon;
use App\Models\Donation;
use App\Models\EcommerceWalletTransaction;
use App\Models\EcommerceConversation;
use App\Models\EcommerceConversationMessage;
use App\Models\EcommerceReferral;
use App\Models\UserLoginHistory;
use App\Models\UserAdminChange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminUserProfileController extends Controller
{
    /**
     * Get complete user profile details including KPI stats and user info.
     */
    public function getProfileDetails(Request $request, $id)
    {
        try {
            $user = User::with(['roles', 'permissions'])->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Real DB calculations
            $ordersCount = EcommerceOrder::where('user_id', $user->id)->count();
            $totalSpent = (float) EcommerceOrder::where('user_id', $user->id)
                ->whereIn('status', ['completed', 'shipped', 'delivered', 'paid', 'processing'])
                ->sum('total_amount');
            
            // If no completed/paid orders found, sum all non-cancelled/non-refunded orders
            if ($totalSpent <= 0) {
                $totalSpent = (float) EcommerceOrder::where('user_id', $user->id)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->sum('total_amount');
            }

            $giftCardBalance = 0.0;
            try {
                $giftCardBalance = (float) EcommerceGiftCard::where(function ($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('recipient_email', $user->email);
                    })
                    ->where('status', 'active')
                    ->sum('current_balance');
            } catch (\Throwable $e) {}

            $referralEarnings = 0.0;
            try {
                $referralEarnings = (float) DB::table('ecommerce_referral_commissions')
                    ->where('referrer_id', $user->id)
                    ->sum('commission_amount');
            } catch (\Throwable $e) {}

            $loyaltyPoints = $user->loyalty_points ?? 0;
            if ($loyaltyPoints === 0) {
                $calculatedPoints = EcommerceLoyaltyTransaction::where('user_id', $user->id)->sum('points');
                if ($calculatedPoints > 0) {
                    $loyaltyPoints = $calculatedPoints;
                }
            }

            $profileData = [
                'id' => $user->id,
                'name' => $user->name,
                'customer_account_number' => $user->customer_account_number ?: ('CUST-' . str_pad($user->id, 7, '0', STR_PAD_LEFT)),
                'avatar' => $user->avatar ?: '',
                'email' => $user->email,
                'phone' => $user->phone ?: '',
                'address' => $user->address ?: '',
                'date_of_birth' => $user->dob ? Carbon::parse($user->dob)->format('F d, Y') : '',
                'dob' => $user->dob ? Carbon::parse($user->dob)->format('Y-m-d') : '',
                'gender' => $user->gender ?: '',
                'membership_status' => $user->membership_status ?: 'Standard Member',
                'member_since' => $user->created_at ? $user->created_at->format('F d, Y') : '',
                'status' => $user->banned_at ? 'banned' : ($user->deactivated_at ? 'deactivated' : 'active'),
                'is_verified' => (bool) $user->email_verified_at,
                'role' => $user->role ?: 'customer',
                'business_name' => $user->business_name ?: '',
                'tax_id' => $user->tax_id ?: '',
                'business_type' => $user->business_type ?: '',
                'metrics' => [
                    'total_orders' => (int) $ordersCount,
                    'total_spent' => round($totalSpent, 2),
                    'loyalty_points' => (int) $loyaltyPoints,
                    'gift_card_balance' => round($giftCardBalance, 2),
                    'referral_earnings' => round($referralEarnings, 2),
                    'account_status' => $user->banned_at ? 'Banned' : ($user->deactivated_at ? 'Deactivated' : 'Active'),
                    'account_standing' => $user->banned_at ? 'Restricted' : 'Good standing',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $profileData,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load profile details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update user profile details and log to admin changes.
     */
    public function updateProfile(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $admin = $request->user();
            $adminId = $admin ? $admin->id : null;
            $adminName = $admin ? $admin->name : 'Administrator';

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:500',
                'dob' => 'nullable|date',
                'gender' => 'nullable|string|max:50',
                'membership_status' => 'nullable|string|max:100',
                'status' => 'nullable|string|in:active,banned,deactivated',
                'business_name' => 'nullable|string|max:255',
                'tax_id' => 'nullable|string|max:100',
                'business_type' => 'nullable|string|max:100',
                'avatar' => 'nullable|string',
            ]);

            $changedFields = [];
            $beforeValues = [];
            $afterValues = [];

            foreach ($validated as $key => $value) {
                if ($key === 'status') {
                    $currentStatus = $user->banned_at ? 'banned' : ($user->deactivated_at ? 'deactivated' : 'active');
                    if ($currentStatus !== $value) {
                        $changedFields[] = 'Status';
                        $beforeValues['Status'] = $currentStatus;
                        $afterValues['Status'] = $value;
                        if ($value === 'banned') {
                            $user->banned_at = Carbon::now();
                            $user->deactivated_at = null;
                        } elseif ($value === 'deactivated') {
                            $user->deactivated_at = Carbon::now();
                            $user->banned_at = null;
                        } else {
                            $user->banned_at = null;
                            $user->deactivated_at = null;
                        }
                    }
                } elseif ($user->$key != $value) {
                    $changedFields[] = ucfirst(str_replace('_', ' ', $key));
                    $beforeValues[$key] = $user->$key;
                    $afterValues[$key] = $value;
                    $user->$key = $value;
                }
            }

            $user->save();

            // Record admin audit log if any fields changed
            if (!empty($changedFields)) {
                UserAdminChange::create([
                    'user_id' => $user->id,
                    'admin_id' => $adminId,
                    'actor_name' => $adminName,
                    'actor_role' => $admin && $admin->role ? ucfirst($admin->role) : 'Administrator',
                    'title' => 'Profile Updated',
                    'description' => 'Admin updated profile details: ' . implode(', ', $changedFields),
                    'changed_fields' => implode(', ', $changedFields),
                    'before_value' => json_encode($beforeValues),
                    'after_value' => json_encode($afterValues),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 1. Order History
     */
    public function getOrderHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $status = $request->query('status');
            $query = EcommerceOrder::where('user_id', $user->id)
                ->with(['items.product']);

            if ($status && $status !== 'all') {
                $query->where('status', strtolower($status));
            }

            $totalOrders = (clone $query)->count();
            $totalSpent = (clone $query)->whereNotIn('status', ['cancelled', 'refunded'])->sum('total_amount');

            $orders = $query->orderBy('created_at', 'desc')->paginate($request->query('per_page', 8));

            $formatted = $orders->getCollection()->map(function ($order) {
                $items = $order->items->map(function ($item) {
                    $img = $item->product_options['image_url'] ?? ($item->product ? $item->product->featured_image : null);
                    return [
                        'id' => $item->id,
                        'name' => $item->product_name ?: ($item->product ? $item->product->name : 'Product'),
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'total_price' => (float) $item->total_price,
                        'image' => $img ?: 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?w=300&auto=format&fit=crop&q=80',
                    ];
                });

                $images = $items->pluck('image')->filter()->values()->all();
                if (empty($images)) {
                    $images = ['https://images.unsplash.com/photo-1588850561407-ed78c282e89b?w=300&auto=format&fit=crop&q=80'];
                }

                $statusColors = [
                    'completed' => 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
                    'delivered' => 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
                    'shipped' => 'bg-sky-500/10 text-sky-400 border-sky-500/20',
                    'processing' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                    'pending' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                    'refunded' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                    'cancelled' => 'bg-neutral-500/10 text-neutral-400 border-neutral-500/20',
                ];

                $st = strtolower($order->status);
                $statusColor = $statusColors[$st] ?? 'bg-neutral-500/10 text-neutral-400 border-neutral-500/20';

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?: ('ORD-' . $order->id),
                    'status' => strtoupper($order->status),
                    'statusColor' => $statusColor,
                    'images' => $images,
                    'items_count' => $items->sum('quantity') ?: ($items->count() ?: 1),
                    'total_amount' => (float) $order->total_amount,
                    'payment_method' => $order->payment_method ?: 'Credit Card',
                    'payment_status' => $order->payment_status ?: 'paid',
                    'tracking_id' => $order->tracking_number ?: 'N/A',
                    'date' => $order->created_at ? $order->created_at->format('M d, Y') : '',
                    'time' => $order->created_at ? $order->created_at->format('h:i A') : '',
                    'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                    'items' => $items,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                    'total_orders' => $totalOrders,
                    'total_spent' => round((float) $totalSpent, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. Login History
     */
    public function getLoginHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $logins = UserLoginHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($request->query('per_page', 10));

            $formatted = $logins->getCollection()->map(function ($log) {
                return [
                    'id' => $log->id,
                    'date' => $log->created_at ? $log->created_at->format('M d, Y') : '',
                    'time' => $log->created_at ? $log->created_at->format('h:i A') : '',
                    'relativeTime' => $log->created_at ? $log->created_at->diffForHumans() : '',
                    'deviceType' => $log->device_type ?: 'desktop',
                    'deviceTitle' => $log->device_title ?: 'Web Browser',
                    'deviceDetails' => $log->device_details ?: 'Unknown device',
                    'location' => $log->location ?: 'Grenada, W.I.',
                    'ip' => $log->ip_address ?: '127.0.0.1',
                    'network' => $log->network ?: 'Internet',
                    'status' => $log->status ?: 'Successful',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'meta' => [
                    'current_page' => $logins->currentPage(),
                    'last_page' => $logins->lastPage(),
                    'total' => $logins->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. Admin Changes
     */
    public function getAdminChanges(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $changes = UserAdminChange::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($request->query('per_page', 10));

            $formatted = $changes->getCollection()->map(function ($chg) {
                return [
                    'id' => $chg->id,
                    'date' => $chg->created_at ? $chg->created_at->format('M d, Y') : '',
                    'time' => $chg->created_at ? $chg->created_at->format('h:i A') : '',
                    'actor' => $chg->actor_name ?: 'Administrator',
                    'role' => $chg->actor_role ?: 'Admin',
                    'title' => $chg->title,
                    'description' => $chg->description,
                    'changedFields' => $chg->changed_fields ?: 'Profile Settings',
                    'beforeValue' => $chg->before_value,
                    'afterValue' => $chg->after_value,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'meta' => [
                    'current_page' => $changes->currentPage(),
                    'last_page' => $changes->lastPage(),
                    'total' => $changes->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 4. Messages / Conversations
     */
    public function getMessages(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $conversations = EcommerceConversation::where('user_id', $user->id)
                ->with(['messages.sender', 'latestMessage'])
                ->orderBy('updated_at', 'desc')
                ->get();

            $formatted = $conversations->map(function ($conv) use ($user) {
                $messages = $conv->messages->map(function ($msg) use ($user) {
                    $isStaff = $msg->sender_type === 'admin' || $msg->sender_type === 'staff';
                    return [
                        'id' => $msg->id,
                        'sender' => $isStaff ? 'Admin / Support' : ($user->name ?: 'Customer'),
                        'role' => $isStaff ? 'Support Team' : 'Customer',
                        'time' => $msg->created_at ? $msg->created_at->format('M d, Y • h:i A') : '',
                        'isStaff' => $isStaff,
                        'message' => $msg->message,
                    ];
                });

                $lastMsg = $conv->latestMessage;
                return [
                    'id' => $conv->id,
                    'customer' => $user->name,
                    'title' => $conv->subject ?: 'Conversation #' . $conv->id,
                    'preview' => $lastMsg ? substr($lastMsg->message, 0, 80) . '...' : 'No messages',
                    'time' => $conv->updated_at ? $conv->updated_at->diffForHumans() : '',
                    'date' => $conv->created_at ? $conv->created_at->format('M d, Y') : '',
                    'unread' => false,
                    'messages' => $messages,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send Message / Reply
     */
    public function sendMessage(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $validated = $request->validate([
                'conversation_id' => 'nullable|integer',
                'subject' => 'nullable|string|max:255',
                'message' => 'required|string',
            ]);

            $convId = $validated['conversation_id'] ?? null;
            if ($convId) {
                $conversation = EcommerceConversation::where('id', $convId)->where('user_id', $user->id)->first();
            } else {
                $conversation = EcommerceConversation::create([
                    'user_id' => $user->id,
                    'subject' => $validated['subject'] ?: 'Direct Message from Support',
                    'status' => 'open',
                ]);
            }

            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            $admin = $request->user();
            $msg = EcommerceConversationMessage::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'admin',
                'sender_id' => $admin ? $admin->id : 1,
                'message' => $validated['message'],
            ]);

            $conversation->touch();

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => $msg,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 5. Financial Transactions
     */
    public function getFinancialTransactions(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $orders = EcommerceOrder::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
            $giftCards = EcommerceGiftCard::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
            $donations = Donation::where('user_id', $user->id)->orWhere('donor_email', $user->email)->orderBy('created_at', 'desc')->get();

            $transactions = [];

            // Add Orders as Purchases
            foreach ($orders as $order) {
                $isRefund = strtolower($order->status) === 'refunded';
                $transactions[] = [
                    'id' => 'TXN-ORD-' . $order->id,
                    'title' => $isRefund ? ('Refund - Order #' . $order->order_number) : ('Order Purchase #' . $order->order_number),
                    'refId' => $order->order_number ?: ('ORD-' . $order->id),
                    'type' => $isRefund ? 'refund' : 'purchase',
                    'amount' => (float) $order->total_amount,
                    'status' => strtoupper($order->status),
                    'paymentMethod' => $order->payment_method ?: 'Credit Card',
                    'date' => $order->created_at ? $order->created_at->format('M d, Y') : '',
                    'time' => $order->created_at ? $order->created_at->format('h:i A') : '',
                    'timestamp' => $order->created_at ? $order->created_at->timestamp : 0,
                ];
            }

            // Add Gift Cards
            foreach ($giftCards as $gc) {
                $transactions[] = [
                    'id' => 'TXN-GC-' . $gc->id,
                    'title' => 'Gift Card Purchase (' . $gc->code . ')',
                    'refId' => $gc->code,
                    'type' => 'gift_card',
                    'amount' => (float) $gc->initial_balance,
                    'status' => strtoupper($gc->status),
                    'paymentMethod' => 'Store Gift Card',
                    'date' => $gc->created_at ? $gc->created_at->format('M d, Y') : '',
                    'time' => $gc->created_at ? $gc->created_at->format('h:i A') : '',
                    'timestamp' => $gc->created_at ? $gc->created_at->timestamp : 0,
                ];
            }

            // Add Donations
            foreach ($donations as $don) {
                $transactions[] = [
                    'id' => 'TXN-DON-' . $don->id,
                    'title' => 'Charity Donation (' . $don->charity_name . ')',
                    'refId' => 'DON-' . $don->id,
                    'type' => 'donation',
                    'amount' => (float) $don->amount,
                    'status' => strtoupper($don->status),
                    'paymentMethod' => $don->payment_method_details ?: 'Credit Card',
                    'date' => $don->created_at ? $don->created_at->format('M d, Y') : '',
                    'time' => $don->created_at ? $don->created_at->format('h:i A') : '',
                    'timestamp' => $don->created_at ? $don->created_at->timestamp : 0,
                ];
            }

            // Sort all transactions chronologically descending
            usort($transactions, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

            $completedOrdersTotal = $orders->whereIn('status', ['completed', 'shipped', 'delivered', 'paid'])->sum('total_amount');
            $refundsTotal = $orders->where('status', 'refunded')->sum('total_amount');
            $giftCardsTotal = $giftCards->sum('initial_balance');
            $donationsTotal = $donations->sum('amount');
            $netAmount = $completedOrdersTotal - $refundsTotal + $giftCardsTotal;

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'breakdown' => [
                    'completedOrders' => (float) $completedOrdersTotal,
                    'refunds' => (float) $refundsTotal,
                    'giftCards' => (float) $giftCardsTotal,
                    'donations' => (float) $donationsTotal,
                    'netAmount' => (float) $netAmount,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 6. Affiliate History
     */
    public function getAffiliateHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $referrals = EcommerceReferral::where('referrer_id', $user->id)
                ->with('referred')
                ->orderBy('created_at', 'desc')
                ->get();

            $signups = $referrals->map(function ($ref) {
                $refUser = $ref->referred;
                return [
                    'id' => $ref->id,
                    'name' => $refUser ? $refUser->name : ($ref->referred_email ?: 'Referred User'),
                    'email' => $refUser ? $refUser->email : ($ref->referred_email ?: ''),
                    'avatar' => $refUser && $refUser->avatar ? $refUser->avatar : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&auto=format&fit=crop&q=80',
                    'date' => $ref->created_at ? $ref->created_at->format('M d, Y') : '',
                    'status' => $ref->status ?: 'Confirmed',
                    'reward' => (float) ($ref->reward_amount_referrer ?? 20.00),
                ];
            });

            // Get referral purchases/commissions if any
            $purchases = [];
            try {
                $commissions = DB::table('ecommerce_referral_commissions')
                    ->where('referrer_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->get();

                $purchases = $commissions->map(function ($comm) {
                    return [
                        'id' => $comm->id,
                        'order_id' => $comm->order_id,
                        'commission_amount' => (float) $comm->commission_amount,
                        'order_amount' => (float) ($comm->order_amount ?? 0),
                        'status' => $comm->status ?? 'paid',
                        'date' => $comm->created_at ? Carbon::parse($comm->created_at)->format('M d, Y') : '',
                    ];
                });
            } catch (\Throwable $e) {}

            $totalRewards = $signups->sum('reward');
            $totalCommissions = collect($purchases)->sum('commission_amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'signups' => $signups,
                    'purchases' => $purchases,
                    'stats' => [
                        'total_signups' => $signups->count(),
                        'confirmed_signups' => $signups->where('status', 'Confirmed')->count(),
                        'signup_rewards' => (float) $totalRewards,
                        'commission_earnings' => (float) $totalCommissions,
                        'total_earnings' => (float) ($totalRewards + $totalCommissions),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 7. Membership History
     */
    public function getMembershipHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $memberships = EcommerceMembership::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $memberships->map(function ($m) {
                $isActive = strtolower($m->status) === 'active';
                return [
                    'id' => $m->id,
                    'date' => $m->created_at ? $m->created_at->format('M d, Y') : '',
                    'time' => $m->created_at ? $m->created_at->format('h:i A') : '',
                    'action' => $isActive ? 'JOINED / ACTIVE' : 'EXPIRED',
                    'plan' => $m->plan_name ?: 'Standard Membership',
                    'badge' => $isActive ? 'PLATINUM' : 'GOLD',
                    'badgeColor' => $isActive ? 'bg-purple-500/20 text-purple-400' : 'bg-amber-500/20 text-amber-400',
                    'status' => strtoupper($m->status),
                    'statusColor' => $isActive ? 'bg-emerald-500/10 text-emerald-400' : 'bg-neutral-500/10 text-neutral-400',
                    'amount' => (float) $m->price,
                    'payment' => 'Yearly Subscription',
                    'notes' => 'Next billing: ' . ($m->next_billing_date ? Carbon::parse($m->next_billing_date)->format('M d, Y') : 'N/A'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 8. Loyalty Program
     */
    public function getLoyaltyProgram(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $transactions = EcommerceLoyaltyTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $earned = $transactions->where('points', '>', 0)->values()->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'date' => $tx->created_at ? $tx->created_at->format('M d, Y') : '',
                    'time' => $tx->created_at ? $tx->created_at->format('h:i A') : '',
                    'activity' => $tx->reason ?: 'Loyalty Points Earned',
                    'details' => $tx->reason_details ?: '',
                    'points' => '+' . number_format($tx->points),
                    'dollar' => '$' . number_format($tx->dollar_value ?: ($tx->points * 0.01), 2),
                    'status' => strtoupper($tx->status ?: 'COMPLETED'),
                ];
            });

            $redeemed = $transactions->where('points', '<', 0)->values()->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'date' => $tx->created_at ? $tx->created_at->format('M d, Y') : '',
                    'time' => $tx->created_at ? $tx->created_at->format('h:i A') : '',
                    'reward' => $tx->reason ?: 'Reward Redemption',
                    'details' => $tx->reason_details ?: '',
                    'points' => number_format($tx->points),
                    'status' => strtoupper($tx->status ?: 'COMPLETED'),
                ];
            });

            $adjusted = $transactions->where('transaction_type', 'adjustment')->values()->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'date' => $tx->created_at ? $tx->created_at->format('M d, Y') : '',
                    'time' => $tx->created_at ? $tx->created_at->format('h:i A') : '',
                    'reason' => $tx->reason ?: 'Manual Adjustment',
                    'details' => $tx->reason_details ?: '',
                    'points' => ($tx->points > 0 ? '+' : '') . number_format($tx->points),
                    'status' => strtoupper($tx->status ?: 'COMPLETED'),
                ];
            });

            $totalEarned = $transactions->where('points', '>', 0)->sum('points');
            $totalRedeemed = abs($transactions->where('points', '<', 0)->sum('points'));
            $available = $user->loyalty_points ?: max(0, $totalEarned - $totalRedeemed);

            return response()->json([
                'success' => true,
                'data' => [
                    'available_points' => (int) $available,
                    'lifetime_earned' => (int) $totalEarned,
                    'points_redeemed' => (int) $totalRedeemed,
                    'points_expired' => 0,
                    'current_tier' => 'Gold Tier',
                    'earned_history' => $earned,
                    'redemption_history' => $redeemed,
                    'adjustment_history' => $adjusted,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Adjust Loyalty Points
     */
    public function adjustLoyaltyPoints(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $validated = $request->validate([
                'points' => 'required|integer',
                'reason' => 'required|string|max:255',
                'details' => 'nullable|string|max:500',
            ]);

            $admin = $request->user();
            $points = (int) $validated['points'];

            $tx = EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'transaction_type' => 'adjustment',
                'points' => $points,
                'dollar_value' => abs($points) * 0.01,
                'status' => 'completed',
                'reason' => $validated['reason'],
                'reason_details' => $validated['details'] ?? 'Admin adjustment',
                'admin_id' => $admin ? $admin->id : null,
            ]);

            $user->loyalty_points = max(0, ($user->loyalty_points ?? 0) + $points);
            $user->save();

            UserAdminChange::create([
                'user_id' => $user->id,
                'admin_id' => $admin ? $admin->id : null,
                'actor_name' => $admin ? $admin->name : 'Administrator',
                'title' => 'Loyalty Points Adjusted',
                'description' => ($points > 0 ? "Added {$points} points" : "Deducted " . abs($points) . " points") . " - Reason: " . $validated['reason'],
                'changed_fields' => 'Loyalty Points',
                'before_value' => (string) ($user->loyalty_points - $points),
                'after_value' => (string) $user->loyalty_points,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Loyalty points adjusted successfully',
                'data' => $tx,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 9. Gift Card History
     */
    public function getGiftCardHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $giftCards = EcommerceGiftCard::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('recipient_email', $user->email);
            })->orderBy('created_at', 'desc')->get();

            $formatted = $giftCards->map(function ($gc) {
                return [
                    'id' => $gc->id,
                    'code' => $gc->code,
                    'initial_amount' => (float) $gc->initial_balance,
                    'current_balance' => (float) $gc->current_balance,
                    'status' => strtoupper($gc->status),
                    'recipient_email' => $gc->recipient_email,
                    'sender_name' => $gc->sender_name ?: 'Store Admin',
                    'expires_at' => $gc->expires_at ? Carbon::parse($gc->expires_at)->format('M d, Y') : 'No Expiry',
                    'created_at' => $gc->created_at ? $gc->created_at->format('M d, Y') : '',
                ];
            });

            $totalCards = $giftCards->count();
            $activeBalance = $giftCards->where('status', 'active')->sum('current_balance');
            $redeemedCount = $giftCards->where('status', 'redeemed')->count();

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'stats' => [
                    'total_cards' => $totalCards,
                    'active_balance' => (float) $activeBalance,
                    'redeemed_count' => $redeemedCount,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 10. Support Tickets
     */
    public function getSupportTickets(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $tickets = EcommerceTicket::where('user_id', $user->id)
                ->with(['replies.user'])
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $tickets->map(function ($tkt) use ($user) {
                $replies = $tkt->replies->map(function ($rep) use ($user) {
                    $isStaff = $rep->admin_reply || ($rep->user && in_array($rep->user->role, ['admin', 'super_admin', 'editor', 'staff']));
                    return [
                        'id' => $rep->id,
                        'author' => $isStaff ? 'Support Team' : ($user->name ?: 'Customer'),
                        'role' => $isStaff ? 'Support Agent' : 'Customer',
                        'message' => $rep->message,
                        'time' => $rep->created_at ? $rep->created_at->format('M d, Y • h:i A') : '',
                        'isStaff' => $isStaff,
                    ];
                });

                $statusColors = [
                    'resolved' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                    'closed' => 'bg-neutral-500/10 text-neutral-400 border-neutral-500/20',
                    'in_progress' => 'bg-sky-500/10 text-sky-400 border-sky-500/20',
                    'open' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                ];

                $st = strtolower($tkt->status);
                $statusColor = $statusColors[$st] ?? 'bg-sky-500/10 text-sky-400 border-sky-500/20';

                return [
                    'id' => $tkt->id,
                    'ticket_number' => $tkt->ticket_number ?: ('#TKT-' . $tkt->id),
                    'title' => $tkt->subject ?: 'Support Request',
                    'description' => $tkt->message,
                    'status' => strtoupper(str_replace('_', ' ', $tkt->status)),
                    'statusColor' => $statusColor,
                    'priority' => ucfirst($tkt->priority ?: 'Medium'),
                    'category' => $tkt->category ?: 'General Support',
                    'createdDate' => $tkt->created_at ? $tkt->created_at->format('M d, Y • h:i A') : '',
                    'lastReply' => $tkt->updated_at ? $tkt->updated_at->diffForHumans() : '',
                    'replies' => $replies,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Reply to support ticket
     */
    public function replyTicket(Request $request, $id, $ticketId)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $ticket = EcommerceTicket::where('id', $ticketId)->where('user_id', $user->id)->first();
            if (!$ticket) {
                return response()->json(['success' => false, 'message' => 'Ticket not found'], 404);
            }

            $validated = $request->validate([
                'message' => 'required|string',
                'status' => 'nullable|string',
            ]);

            $admin = $request->user();
            $reply = EcommerceTicketReply::create([
                'ecommerce_ticket_id' => $ticket->id,
                'user_id' => $admin ? $admin->id : 1,
                'admin_reply' => true,
                'message' => $validated['message'],
            ]);

            if (!empty($validated['status'])) {
                $ticket->status = strtolower($validated['status']);
            }
            $ticket->last_staff_reply_at = Carbon::now();
            $ticket->touch();

            return response()->json([
                'success' => true,
                'message' => 'Reply posted successfully',
                'data' => $reply,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 11. Customer Verification History
     */
    public function getVerificationHistory(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $verifications = EcommerceCustomerVerification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $events = [];

            // Add email verification event
            $events[] = [
                'id' => 'ver-email',
                'type' => 'Email Verification',
                'status' => $user->email_verified_at ? 'VERIFIED' : 'PENDING',
                'statusColor' => $user->email_verified_at ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400',
                'date' => $user->email_verified_at ? Carbon::parse($user->email_verified_at)->format('M d, Y') : ($user->created_at ? $user->created_at->format('M d, Y') : ''),
                'verifier' => 'System Automated',
                'details' => $user->email,
            ];

            // Add phone verification event
            $events[] = [
                'id' => 'ver-phone',
                'type' => 'Phone Verification',
                'status' => !empty($user->phone) ? 'VERIFIED' : 'PENDING',
                'statusColor' => !empty($user->phone) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400',
                'date' => $user->created_at ? $user->created_at->format('M d, Y') : '',
                'verifier' => 'SMS Verification',
                'details' => $user->phone ?: 'Not provided',
            ];

            foreach ($verifications as $v) {
                $isVerified = strtolower($v->status) === 'verified';
                $events[] = [
                    'id' => 'ver-doc-' . $v->id,
                    'type' => ($v->document_type ?: 'Identity') . ' Document Verification',
                    'status' => strtoupper($v->status),
                    'statusColor' => $isVerified ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400',
                    'date' => $v->created_at ? $v->created_at->format('M d, Y') : '',
                    'verifier' => 'Compliance Team',
                    'details' => $v->notes ?: 'Document uploaded and verified.',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $events,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 12. Order Disputes
     */
    public function getOrderDisputes(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $disputes = EcommerceDispute::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $disputes->map(function ($dsp) {
                $statusColors = [
                    'resolved' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                    'closed' => 'bg-neutral-500/10 text-neutral-400 border-neutral-500/20',
                    'under review' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                    'open' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                ];

                $st = strtolower($dsp->status);
                $statusColor = $statusColors[$st] ?? 'bg-amber-500/10 text-amber-400 border-amber-500/20';

                return [
                    'id' => $dsp->id,
                    'dispute_number' => $dsp->dispute_number ?: ('DSP-' . $dsp->id),
                    'order_number' => $dsp->order_number ?: 'N/A',
                    'title' => $dsp->type ?: 'Order Dispute',
                    'description' => $dsp->description,
                    'amount' => (float) $dsp->amount,
                    'status' => strtoupper($dsp->status),
                    'statusColor' => $statusColor,
                    'date' => $dsp->created_at ? $dsp->created_at->format('M d, Y') : '',
                    'product_image' => 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?w=300&auto=format&fit=crop&q=80',
                ];
            });

            $totalCount = $disputes->count();
            $openCount = $disputes->whereIn('status', ['OPEN', 'open'])->count();
            $underReviewCount = $disputes->whereIn('status', ['UNDER REVIEW', 'under review', 'in_progress'])->count();
            $resolvedCount = $disputes->whereIn('status', ['RESOLVED', 'resolved', 'CLOSED', 'closed'])->count();
            $currentAmount = $disputes->whereIn('status', ['OPEN', 'open', 'UNDER REVIEW', 'under review'])->sum('amount');
            $lifetimeDisputed = $disputes->sum('amount');

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'stats' => [
                    'total' => $totalCount,
                    'open' => $openCount,
                    'under_review' => $underReviewCount,
                    'resolved' => $resolvedCount,
                    'current_dispute_amount' => (float) $currentAmount,
                    'lifetime_dispute_amount' => (float) $lifetimeDisputed,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 13. Reported Products
     */
    public function getReportedProducts(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $reports = ProductReport::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $reports->map(function ($rpt) {
                $statusColors = [
                    'action taken' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                    'under review' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                    'no violation' => 'bg-neutral-500/10 text-neutral-400 border-neutral-500/20',
                ];

                $st = strtolower($rpt->status);
                $statusColor = $statusColors[$st] ?? 'bg-amber-500/10 text-amber-400 border-amber-500/20';

                return [
                    'id' => $rpt->id,
                    'code' => $rpt->report_code ?: ('RPT-' . $rpt->id),
                    'name' => $rpt->product_name ?: 'Embroidery Product',
                    'issue' => $rpt->issue ?: $rpt->description,
                    'status' => strtoupper($rpt->status),
                    'statusColor' => $statusColor,
                    'date' => $rpt->created_at ? $rpt->created_at->format('M d, Y') : '',
                    'image' => $rpt->product_image ?: 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?w=300&auto=format&fit=crop&q=80',
                ];
            });

            $total = $reports->count();
            $underReview = $reports->whereIn('status', ['UNDER REVIEW', 'under review', 'pending'])->count();
            $actionTaken = $reports->whereIn('status', ['ACTION TAKEN', 'action taken'])->count();
            $noViolation = $reports->whereIn('status', ['NO VIOLATION', 'no violation'])->count();

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'stats' => [
                    'total' => $total,
                    'under_review' => $underReview,
                    'action_taken' => $actionTaken,
                    'no_violation' => $noViolation,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 14. Coupons & Deals
     */
    public function getCoupons(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $coupons = EcommerceCoupon::where('is_active', true)->get();

            $colors = [
                'bg-[#ef0b6e]',
                'bg-[#6366f1]',
                'bg-[#f97316]',
                'bg-[#0070f3]',
                'bg-[#00b074]',
                'bg-[#00a8cc]',
            ];

            $formatted = $coupons->values()->map(function ($c, $idx) use ($colors) {
                $isPercent = $c->discount_type === 'percentage' || str_contains($c->discount_type ?? '', 'percent');
                $isFreeShip = str_contains(strtolower($c->code), 'ship') || str_contains(strtolower($c->title ?? ''), 'shipping');

                $discount = $isFreeShip ? 'FREE' : ($isPercent ? ((int)$c->discount_value . '%') : ('$' . (int)$c->discount_value));
                $discountSub = $isFreeShip ? 'SHIPPING' : 'OFF';

                $status = 'Active';
                $statusColor = 'bg-[#dcfce7] text-[#15803d]';
                if ($c->expires_at && Carbon::parse($c->expires_at)->isPast()) {
                    $status = 'Expired';
                    $statusColor = 'bg-[#ffe4e6] text-[#e11d48]';
                } elseif ($c->starts_at && Carbon::parse($c->starts_at)->isFuture()) {
                    $status = 'Scheduled';
                    $statusColor = 'bg-[#e0e7ff] text-[#4338ca]';
                }

                return [
                    'id' => $c->id,
                    'discount' => $discount,
                    'discountSub' => $discountSub,
                    'code' => $c->code,
                    'status' => $status,
                    'statusColor' => $statusColor,
                    'appliesTo' => $c->applies_to ?: 'All products',
                    'minSpend' => '$' . number_format((float) ($c->min_order_amount ?? 0), 2),
                    'validFrom' => $c->starts_at ? Carbon::parse($c->starts_at)->format('M d, Y') : ($c->created_at ? $c->created_at->format('M d, Y') : 'May 15, 2026'),
                    'validTo' => $c->expires_at ? Carbon::parse($c->expires_at)->format('M d, Y') : 'Jun 30, 2026',
                    'usage' => ($c->used_count ?? 0) . ' / ' . ($c->usage_limit ?: 1) . ' Used',
                    'ribbonBg' => $colors[$idx % count($colors)],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 15. Donations History
     */
    public function getDonations(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $donations = Donation::where('user_id', $user->id)
                ->orWhere('donor_email', $user->email)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $donations->map(function ($d) {
                return [
                    'id' => $d->id,
                    'date' => $d->created_at ? $d->created_at->format('M d, Y') : '',
                    'time' => $d->created_at ? $d->created_at->format('h:i A') : '',
                    'charity' => $d->charity_name,
                    'location' => "St. George's, Grenada",
                    'purpose' => $d->charity_category ?: 'Charity Initiative',
                    'amount' => (float) $d->amount,
                    'payment' => $d->payment_method_details ?: 'Credit Card',
                    'status' => strtoupper($d->status ?: 'COMPLETED'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'stats' => [
                    'total_donated' => (float) $donations->sum('amount'),
                    'count' => $donations->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 16. Customer Downloads
     */
    public function getDownloads(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $files = EcommerceCustomerFile::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $files->map(function ($f) {
                return [
                    'id' => $f->id,
                    'name' => $f->file_name,
                    'type' => strtoupper($f->file_type ?: pathinfo($f->file_name, PATHINFO_EXTENSION)),
                    'size' => round(($f->size_bytes ?: 1024000) / (1024 * 1024), 2) . ' MB',
                    'category' => $f->category ?: 'Artwork & Designs',
                    'download_count' => $f->download_count ?: 0,
                    'date' => $f->created_at ? $f->created_at->format('M d, Y') : '',
                    'url' => $f->file_path ? asset('storage/' . $f->file_path) : '#',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'stats' => [
                    'total_files' => $files->count(),
                    'categories' => $files->groupBy('category')->map->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
