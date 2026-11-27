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
use App\Models\EcommerceVoucher;
use App\Models\EcommerceConversation;
use App\Models\EcommerceConversationMessage;
use App\Models\EcommerceReferral;
use App\Models\UserLoginHistory;
use App\Models\UserAdminChange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
                \App\Services\AffiliateCommissionService::syncSignupCommissions((int) $user->id);
                $approvedStatuses = ['approved', 'completed', 'partially_reversed', 'paid'];
                $allComms = \App\Models\EcommerceReferralCommission::where('referrer_id', $user->id)->get();
                $approvedComms = $allComms->filter(fn ($c) => in_array(strtolower((string) ($c->status ?? '')), $approvedStatuses, true));

                $nonSignupTotal = (float) $approvedComms
                    ->filter(fn ($c) => strtolower((string) ($c->source_type ?? 'order')) !== \App\Services\AffiliateCommissionService::SOURCE_SIGNUP)
                    ->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

                $signupCommTotal = (float) $approvedComms
                    ->filter(fn ($c) => strtolower((string) ($c->source_type ?? 'order')) === \App\Services\AffiliateCommissionService::SOURCE_SIGNUP)
                    ->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

                $refSignupSum = (float) EcommerceReferral::where('referrer_id', $user->id)->sum('reward_amount_referrer');
                $referralEarnings = round($nonSignupTotal + max($signupCommTotal, $refSignupSum), 2);
            } catch (\Throwable $e) {}

            $loyaltyPoints = $user->loyalty_points ?? 0;
            if ($loyaltyPoints === 0) {
                $calculatedPoints = EcommerceLoyaltyTransaction::where('user_id', $user->id)->sum('points');
                if ($calculatedPoints > 0) {
                    $loyaltyPoints = $calculatedPoints;
                }
            }

            $walletBalance = (float) ($user->wallet_balance ?? 0);

            $voucherBalance = 0.0;
            try {
                $voucherBalance = (float) EcommerceVoucher::where(function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                        if ($user->email) {
                            $q->orWhere('holder_email', $user->email);
                        }
                    })
                    ->where('status', EcommerceVoucher::STATUS_ACTIVE)
                    ->sum('balance');
            } catch (\Throwable $e) {}

            $membershipExpires = 'N/A';
            try {
                $activeMembership = EcommerceMembership::where('user_id', $user->id)
                    ->whereRaw('LOWER(status) = ?', ['active'])
                    ->orderBy('created_at', 'desc')
                    ->first();
                if ($activeMembership && $activeMembership->next_billing_date) {
                    $membershipExpires = Carbon::parse($activeMembership->next_billing_date)->format('F d, Y');
                }
            } catch (\Throwable $e) {}

            $isBusiness = strtolower((string) ($user->role ?? '')) === 'business'
                || strtolower((string) $request->query('type', '')) === 'business'
                || !empty($user->business_name);
            $accountNumber = $user->customer_account_number;
            if ($accountNumber) {
                if ($isBusiness && str_starts_with($accountNumber, 'ME') && !str_starts_with($accountNumber, 'MEB')) {
                    $accountNumber = 'MEB' . substr($accountNumber, 2);
                }
            } else {
                $year = $user->created_at ? $user->created_at->format('Y') : now()->format('Y');
                $prefix = $isBusiness ? 'MEB' : 'ME';
                $accountNumber = $prefix . $year . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
            }

            $lastLogin = null;
            try {
                $lastLogin = UserLoginHistory::where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->get(['status', 'created_at'])
                    ->first(fn ($l) => !preg_match('/fail|denied|blocked|invalid/i', (string) $l->status))
                    ?->created_at;
            } catch (\Throwable $e) {}

            $walletLastActivity = null;
            try {
                $walletLastActivity = DB::table('ecommerce_wallet_accounts')
                    ->where('user_id', $user->id)
                    ->value('last_activity_at');
            } catch (\Throwable $e) {}

            $lastActivity = collect([
                $user->last_login_at,
                $lastLogin ? Carbon::parse($lastLogin) : null,
                $walletLastActivity ? Carbon::parse($walletLastActivity) : null,
                $user->updated_at,
            ])->filter()->max();

            $profileData = [
                'id' => $user->id,
                'name' => $user->name,
                'customer_account_number' => $accountNumber,
                'avatar' => $user->avatar ?: '',
                'email' => $user->email,
                'phone' => $user->phone ?: '',
                'address' => $user->address ?: '',
                'date_of_birth' => $user->dob ? Carbon::parse($user->dob)->format('F d, Y') : '',
                'dob' => $user->dob ? Carbon::parse($user->dob)->format('Y-m-d') : '',
                'gender' => $user->gender ?: '',
                'membership_status' => $user->membership_status ?: 'Standard Member',
                'membership_expires' => $membershipExpires,
                'member_since' => $user->created_at ? $user->created_at->format('F d, Y') : '',
                'joined_at' => $user->created_at?->toIso8601String(),
                'last_activity_at' => $lastActivity?->toIso8601String(),
                'status' => $user->banned_at ? 'banned' : ($user->deactivated_at ? 'deactivated' : 'active'),
                'is_verified' => (bool) $user->email_verified_at,
                'role' => $user->role ?: 'customer',
                'business_name' => $user->business_name ?: '',
                'tax_id' => $user->tax_id ?: '',
                'business_type' => $user->business_type ?: '',
                'risk_level' => $user->risk_level ?: 'low',
                'risk_reason' => $user->risk_reason ?: '',
                'benefit_eligible' => (bool) ($user->benefit_eligible ?? true),
                'metrics' => [
                    'total_orders' => (int) $ordersCount,
                    'total_spent' => round($totalSpent, 2),
                    'loyalty_points' => (int) $loyaltyPoints,
                    'wallet_balance' => round($walletBalance, 2),
                    'gift_card_balance' => round($giftCardBalance, 2),
                    'voucher_balance' => round($voucherBalance, 2),
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
                'username' => 'nullable|string|max:100|unique:users,username,' . $user->id,
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:500',
                'dob' => 'nullable|date',
                'gender' => 'nullable|string|max:50',
                'membership_status' => 'nullable|string|max:100',
                'status' => 'nullable|string|in:active,banned,deactivated',
                'business_name' => 'nullable|string|max:255',
                'tax_id' => 'nullable|string|max:100',
                'business_type' => 'nullable|string|max:100',
                'job_title' => 'nullable|string|max:100',
                'avatar' => 'nullable|string',
                'password' => 'nullable|string|min:6',
                'benefit_eligible' => 'sometimes|boolean',
                'risk_level' => 'sometimes|string|in:low,medium,high',
            ]);

            $changedFields = [];
            $beforeValues = [];
            $afterValues = [];

            foreach ($validated as $key => $value) {
                if ($key === 'password') {
                    if (!empty($value)) {
                        $changedFields[] = 'Password';
                        $beforeValues['Password'] = '[hidden]';
                        $afterValues['Password'] = '[updated]';
                        $user->password = Hash::make($value);
                    }
                    continue;
                }
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
                } elseif ($key === 'benefit_eligible') {
                    $currentEligible = (bool) ($user->benefit_eligible ?? true);
                    $nextEligible = (bool) $value;
                    if ($currentEligible !== $nextEligible) {
                        $changedFields[] = 'Benefit eligible';
                        $beforeValues['benefit_eligible'] = $currentEligible;
                        $afterValues['benefit_eligible'] = $nextEligible;
                        $user->benefit_eligible = $nextEligible;
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
                    'image' => $img ?: null,
                    ];
                });

                $images = $items->pluck('image')->filter()->values()->all();
                $pm = (string) ($order->payment_method ?: '');
                $pmBrand = $pm;
                $pmLast4 = null;
                if (preg_match('/(\d{4})\s*$/', $pm, $mm)) {
                    $pmLast4 = $mm[1];
                    $pmBrand = trim(preg_replace('/(ending in|ending|\*+|•+|x+|-|#)?\s*\d{4}\s*$/i', '', $pm));
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
                    'payment_method' => $order->payment_method ?: 'N/A',
                    'payment_brand' => $pmBrand ?: ($order->payment_method ?: 'N/A'),
                    'payment_last4' => $pmLast4,
                    'shipping_method' => $order->shipping_method ?: null,
                    'shipping_carrier' => $order->tracking_carrier ?: null,
                    'shipping_eta' => $order->estimated_delivery_at ? $order->estimated_delivery_at->format('M d, Y') : null,
                    'shipping_address' => $order->shipping_address ?: null,
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
                    'summary' => (function () use ($user) {
                        $base = EcommerceOrder::where('user_id', $user->id);
                        $cnt = fn (array $s) => (clone $base)->whereIn(DB::raw('LOWER(status)'), $s)->count();
                        return [
                            'total' => (clone $base)->count(),
                            'completed' => $cnt(['completed', 'delivered']),
                            'returned' => $cnt(['returned', 'refunded']),
                            'canceled' => $cnt(['cancelled', 'canceled']),
                        ];
                    })(),
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
                    'location' => $log->location ?: 'Unknown',
                    'ip' => $log->ip_address ?: '—',
                    'network' => $log->network ?: 'Internet',
                    'status' => $log->status ?: 'Successful',
                    'failure_reason' => strtolower((string) $log->status) === 'failed' ? ($log->failure_reason ?? 'Authentication failed') : null,
                    'security_flag' => strtolower((string) $log->status) === 'failed' ? 'Suspicious' : 'Normal',
                    'session_status' => strtolower((string) $log->status) === 'failed' ? 'Failed' : 'Logged Out',
                ];
            });
            $hasActiveToken = DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', get_class($user))
                ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
                ->exists();
            $latestOk = UserLoginHistory::where('user_id', $user->id)->whereRaw('LOWER(status) != ?', ['failed'])->orderBy('created_at', 'desc')->first();
            $formatted = $formatted->map(function ($row) use ($hasActiveToken, $latestOk) {
                if ($hasActiveToken && $latestOk && $row['id'] === $latestOk->id) {
                    $row['session_status'] = 'Active';
                }
                return $row;
            });
            $failedCount = UserLoginHistory::where('user_id', $user->id)->whereRaw('LOWER(status) = ?', ['failed'])->count();
            $totalCount = UserLoginHistory::where('user_id', $user->id)->count();

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'meta' => [
                    'current_page' => $logins->currentPage(),
                    'last_page' => $logins->lastPage(),
                    'total' => $logins->total(),
                    'summary' => [
                        'total' => $totalCount,
                        'successful' => $totalCount - $failedCount,
                        'failed' => $failedCount,
                        'active_sessions' => ($hasActiveToken && $latestOk) ? 1 : 0,
                        'security_flags' => $failedCount,
                    ],
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
                        'attachment_url' => !empty($msg->attachment_path) ? asset('storage/' . ltrim($msg->attachment_path, '/')) : null,
                        'attachment_name' => $msg->attachment_name ?: null,
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
                    'unread_count' => $conv->messages->filter(fn ($m) => !in_array($m->sender_type, ['admin', 'staff'], true) && !$m->read_at)->count(),
                    'unread' => $conv->messages->filter(fn ($m) => !in_array($m->sender_type, ['admin', 'staff'], true) && !$m->read_at)->count() > 0,
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
                'message' => 'required_without:attachment|nullable|string',
                'attachment' => 'nullable|file|max:10240',
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

            $attachmentPath = null;
            $attachmentName = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $attachmentName = $file->getClientOriginalName();
                $attachmentPath = $file->store('conversation-attachments/' . $conversation->id, 'public');
            }

            $admin = $request->user();
            $payload = [
                'conversation_id' => $conversation->id,
                'sender_type' => 'admin',
                'sender_id' => $admin ? $admin->id : 1,
                'message' => (string) ($validated['message'] ?? ''),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('ecommerce_conversation_messages', 'attachment_path')) {
                $payload['attachment_path'] = $attachmentPath;
                $payload['attachment_name'] = $attachmentName;
            }
            $msg = EcommerceConversationMessage::create($payload);

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
                     'kind' => $isRefund ? 'refund' : 'purchase',
                     'txn' => 'TRX-' . $order->id,
                     'orderRef' => $order->order_number ?: ('ORD-' . $order->id),
                     'description' => $isRefund ? ('Refund for order #' . ($order->order_number ?: $order->id)) : ('Purchase for order #' . ($order->order_number ?: $order->id)),
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
                     'kind' => 'credit',
                     'txn' => 'GC-' . $gc->id,
                     'orderRef' => $gc->code,
                     'description' => 'Store Credit Issued',
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
                     'kind' => 'payment',
                     'txn' => 'DON-' . $don->id,
                     'orderRef' => 'DON-' . $don->id,
                     'description' => 'Donation to ' . ($don->charity_name ?: 'charity'),
                    'amount' => (float) $don->amount,
                    'status' => strtoupper($don->status),
                    'paymentMethod' => $don->payment_method_details ?: 'Credit Card',
                    'date' => $don->created_at ? $don->created_at->format('M d, Y') : '',
                    'time' => $don->created_at ? $don->created_at->format('h:i A') : '',
                    'timestamp' => $don->created_at ? $don->created_at->timestamp : 0,
                ];
            }

            // Wallet credits / debits (real wallet ledger)
            try {
                $walletTxs = EcommerceWalletTransaction::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
                foreach ($walletTxs as $wt) {
                    $isDebit = in_array(strtolower(trim((string) $wt->type)), ['debit', 'withdrawal', 'spend', 'purchase', 'deduction', 'manual_removed', 'order payment', 'payment'], true) || (float) $wt->amount < 0;
                    $amt = abs((float) $wt->amount);
                    $transactions[] = [
                        'id' => 'TXN-WAL-' . $wt->id,
                        'title' => $wt->description ?: ($isDebit ? 'Wallet Debit' : 'Wallet Credit'),
                        'refId' => $wt->reference_id ?: ('WAL-' . $wt->id),
                        'type' => $isDebit ? 'adjustment' : 'credit',
                        'kind' => $isDebit ? 'adjustment' : 'credit',
                        'txn' => 'WAL-' . $wt->id,
                        'orderRef' => $wt->reference_id ?: ('WAL-' . $wt->id),
                        'description' => $wt->description ?: ($wt->reason ?: ($isDebit ? 'Wallet debit' : 'Wallet credit')),
                        'amount' => $isDebit ? -$amt : $amt,
                        'status' => strtoupper($wt->status ?: 'COMPLETED'),
                        'paymentMethod' => 'Wallet',
                        'date' => $wt->created_at ? $wt->created_at->format('M d, Y') : '',
                        'time' => $wt->created_at ? $wt->created_at->format('h:i A') : '',
                        'timestamp' => $wt->created_at ? $wt->created_at->timestamp : 0,
                    ];
                }
            } catch (\Throwable $e) {
            }

            // Sort all transactions chronologically descending
            usort($transactions, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

            $completedOrdersTotal = $orders->whereIn('status', ['completed', 'shipped', 'delivered', 'paid'])->sum('total_amount');
            $refundsTotal = $orders->where('status', 'refunded')->sum('total_amount');
            $giftCardsTotal = $giftCards->sum('initial_balance');
            $donationsTotal = $donations->sum('amount');
            $netAmount = $completedOrdersTotal - $refundsTotal + $giftCardsTotal;

            $profileStats = [
                'risk_score' => 100, 'risk_label' => 'Low Risk', 'total_orders' => $orders->count(),
                'return_orders' => 0, 'disputed_orders' => 0, 'total_spent' => (float) $completedOrdersTotal,
                'total_refunds' => (float) $refundsTotal, 'total_chargeback' => 0.0,
            ];
            try {
                $userDisputes = EcommerceDispute::where('user_id', $user->id)->get();
                $returnCount = $orders->filter(fn($o) => in_array(strtolower((string) $o->status), ['returned', 'return_requested', 'refunded']))->count();
                $chargebackTotal = $userDisputes->filter(fn($d) => stripos((string) $d->type, 'chargeback') !== false)->sum('amount');
                $score = 100 - ($userDisputes->count() * 8) - ($returnCount * 3) - ((int) ($chargebackTotal > 0) * 15);
                $score = max(0, min(100, $score));
                $profileStats['risk_score'] = $score;
                $profileStats['risk_label'] = $score >= 70 ? 'Low Risk' : ($score >= 40 ? 'Medium Risk' : 'High Risk');
                $profileStats['return_orders'] = $returnCount;
                $profileStats['disputed_orders'] = $userDisputes->count();
                $profileStats['total_chargeback'] = (float) $chargebackTotal;
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'stats' => $profileStats,
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

            try {
                \App\Services\AffiliateCommissionService::syncSignupCommissions((int) $user->id);
            } catch (\Throwable $e) {}

            $affiliate = \App\Models\EcommerceAffiliate::where('user_id', $user->id)->first();

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
                    'avatar' => $refUser && $refUser->avatar ? $refUser->avatar : null,
                    'date' => $ref->created_at ? $ref->created_at->format('M d, Y') : '',
                    'status' => $ref->status ?: 'Confirmed',
                    'reward' => (float) ($ref->reward_amount_referrer ?? 0.00),
                ];
            });

            $allCommissions = collect();
            try {
                $allCommissions = \App\Models\EcommerceReferralCommission::with(['referred', 'order.user'])
                    ->where('referrer_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->get();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('AdminUserProfileController::getAffiliateHistory commissions query failed: ' . $e->getMessage());
            }

            $signupCommissions = $allCommissions->filter(
                fn ($c) => strtolower((string) ($c->source_type ?? 'order')) === \App\Services\AffiliateCommissionService::SOURCE_SIGNUP
            );
            $orderCommissions = $allCommissions->filter(
                fn ($c) => strtolower((string) ($c->source_type ?? 'order')) !== \App\Services\AffiliateCommissionService::SOURCE_SIGNUP
            );

            $purchases = $orderCommissions->map(function ($comm) {
                $net = max(0.0, (float) $comm->commission_amount - (float) ($comm->refunded_amount ?? 0));
                $order = $comm->order;
                $referred = $comm->referred ?: $order?->user;
                $orderAmount = (float) ($comm->order_amount ?? 0);
                if ($orderAmount <= 0 && $order) {
                    $orderAmount = (float) ($order->total_amount ?? 0);
                }

                return [
                    'id' => $comm->id,
                    'order_id' => $comm->order_id,
                    'order_number' => $order?->order_number ?: ($comm->order_id ? ('ORD-' . $comm->order_id) : null),
                    'referred_name' => $referred?->name,
                    'referred_avatar' => $referred?->avatar,
                    'source_type' => $comm->source_type ?? 'order',
                    'commission_amount' => (float) $comm->commission_amount,
                    'refunded_amount' => (float) ($comm->refunded_amount ?? 0),
                    'net_commission_amount' => $net,
                    'order_amount' => $orderAmount,
                    'status' => $comm->status ?? 'paid',
                    'date' => $comm->created_at ? Carbon::parse($comm->created_at)->format('M d, Y') : '',
                ];
            })->values();

            // Also include referred orders (or orders tagged with affiliate_id) that don't have a commission row yet
            try {
                $referredUserIds = $referrals->pluck('referred_id')->filter()->unique();
                if ($referredUserIds->isNotEmpty() || $affiliate) {
                    $existingOrderIds = $orderCommissions->pluck('order_id')->filter()->unique()->all();
                    $extraOrders = EcommerceOrder::with('user')
                        ->where(function ($q) use ($referredUserIds, $affiliate) {
                            if ($referredUserIds->isNotEmpty()) {
                                $q->whereIn('user_id', $referredUserIds);
                            }
                            if ($affiliate && \Illuminate\Support\Facades\Schema::hasColumn('ecommerce_orders', 'affiliate_id')) {
                                $q->orWhere('affiliate_id', $affiliate->id);
                            }
                        })
                        ->when(!empty($existingOrderIds), fn ($q) => $q->whereNotIn('id', $existingOrderIds))
                        ->orderBy('created_at', 'desc')
                        ->get();

                    foreach ($extraOrders as $ord) {
                        $purchases->push([
                            'id' => 'order-' . $ord->id,
                            'order_id' => $ord->id,
                            'order_number' => $ord->order_number ?: ('ORD-' . $ord->id),
                            'referred_name' => $ord->user?->name,
                            'referred_avatar' => $ord->user?->avatar,
                            'source_type' => 'order',
                            'commission_amount' => 0.0,
                            'refunded_amount' => 0.0,
                            'net_commission_amount' => 0.0,
                            'order_amount' => (float) ($ord->total_amount ?? 0),
                            'status' => $ord->status ?? 'pending',
                            'date' => $ord->created_at ? Carbon::parse($ord->created_at)->format('M d, Y') : '',
                        ]);
                    }
                }
            } catch (\Throwable $e) {}

            $approvedStatuses = ['completed', 'partially_reversed', 'approved', 'paid'];

            $signupCommTotal = (float) $signupCommissions
                ->filter(fn ($c) => in_array(strtolower((string) ($c->status ?? '')), $approvedStatuses, true))
                ->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            $totalRewards = max($signupCommTotal, (float) $signups->sum('reward'));

            $totalCommissions = (float) $orderCommissions
                ->filter(fn ($c) => in_array(strtolower((string) ($c->status ?? '')), $approvedStatuses, true))
                ->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            $confirmedSignups = $signups->filter(
                fn ($s) => in_array(strtolower((string) ($s['status'] ?? '')), ['confirmed', 'active', 'completed'], true)
            )->count();

            $pendingSignupRefs = (float) $referrals->filter(
                fn ($r) => in_array(strtolower((string) ($r->status ?? '')), ['pending', 'registered'], true)
            )->sum('reward_amount_referrer');

            $pendingSignupComms = (float) $signupCommissions->filter(
                fn ($c) => strtolower((string) ($c->status ?? '')) === 'pending'
            )->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            $pendingCommission = (float) $orderCommissions->filter(
                fn ($c) => strtolower((string) ($c->status ?? '')) === 'pending'
            )->sum(fn ($c) => max(0.0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            return response()->json([
                'success' => true,
                'data' => [
                    'signups' => $signups->values(),
                    'purchases' => $purchases->values(),
                    'stats' => [
                        'total_signups' => $signups->count(),
                        'confirmed_signups' => $confirmedSignups,
                        'signup_rewards' => round($totalRewards, 2),
                        'commission_earnings' => round($totalCommissions, 2),
                        'total_earnings' => round($totalRewards + $totalCommissions, 2),
                        'pending_signup_payout' => round(max($pendingSignupRefs, $pendingSignupComms), 2),
                        'total_orders' => $purchases->count(),
                        'pending_commission' => round($pendingCommission, 2),
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

            $planMap = collect();
            try {
                $planMap = DB::table('ecommerce_subscription_plans')
                    ->whereIn('id', $memberships->pluck('plan_id')->filter()->unique()->all())
                    ->get()->keyBy('id');
            } catch (\Throwable $e) {}

            $activityPayments = collect();
            try {
                $activityPayments = \App\Models\EcommerceMembershipActivity::where('user_id', $user->id)
                    ->whereNotNull('payment_method')
                    ->orderBy('created_at', 'desc')->get()->groupBy('membership_id');
            } catch (\Throwable $e) {}

            $defaultPm = null;
            try {
                $defaultPm = \App\Models\EcommercePaymentMethod::where('user_id', $user->id)
                    ->orderByDesc('is_default')->orderByDesc('id')->first();
            } catch (\Throwable $e) {}
            $defaultPmLabel = $defaultPm ? trim(strtoupper((string) $defaultPm->brand) . ' •••• ' . $defaultPm->last4) : null;

            $formatted = $memberships->map(function ($m) use ($memberships, $planMap, $activityPayments, $defaultPmLabel) {
                $prev = $memberships->filter(fn($o) => $o->id !== $m->id && $o->created_at && $m->created_at && $o->created_at->lt($m->created_at))->sortByDesc('created_at')->first();
                $stLower = strtolower((string) $m->status);
                if (in_array($stLower, ['cancelled', 'canceled'])) { $eventType = 'CANCELLED'; }
                elseif (!$prev) { $eventType = 'JOINED'; }
                elseif ((float) $m->price > (float) $prev->price) { $eventType = 'UPGRADED'; }
                elseif ((float) $m->price < (float) $prev->price) { $eventType = 'DOWNGRADED'; }
                else { $eventType = 'RENEWED'; }
                $noteText = $eventType === 'UPGRADED' ? ('Upgraded from ' . $prev->plan_name)
                    : ($eventType === 'DOWNGRADED' ? ('Downgraded from ' . $prev->plan_name)
                    : ($eventType === 'RENEWED' ? 'Renewal' : ($eventType === 'CANCELLED' ? ($m->cancellation_reason ?: 'Membership cancelled') : 'Initial membership activated')));
                $isActive = strtolower($m->status) === 'active';
                return [
                    'id' => $m->id,
                    'date' => $m->created_at ? $m->created_at->format('M d, Y') : '',
                    'time' => $m->created_at ? $m->created_at->format('h:i A') : '',
                    'action' => $isActive ? 'JOINED / ACTIVE' : 'EXPIRED',
                     'event_type' => $eventType,
                     'billing_cycle' => $m->billing_cycle ?: 'yearly',
                     'note_text' => $noteText,
                     'status_key' => $stLower,
                    'plan' => $m->plan_name ?: 'Standard Membership',
                    'badge' => strtoupper((string) (($planMap[$m->plan_id]->tier ?? null) ?: ($planMap[$m->plan_id]->badge ?? null) ?: ($isActive ? 'ACTIVE' : 'MEMBER'))),
                    'badgeColor' => $isActive ? 'bg-purple-500/20 text-purple-400' : 'bg-amber-500/20 text-amber-400',
                    'status' => strtoupper($m->status),
                    'statusColor' => $isActive ? 'bg-emerald-500/10 text-emerald-400' : 'bg-neutral-500/10 text-neutral-400',
                    'amount' => (float) $m->price,
                    'payment' => optional($activityPayments->get($m->id)?->first())->payment_method ?: ($defaultPmLabel ?: '—'),
                    'notes' => 'Next billing: ' . ($m->next_billing_date ? Carbon::parse($m->next_billing_date)->format('M d, Y') : 'N/A'),
                ];
            });

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 5);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
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

            $summary = \App\Services\LoyaltyService::getSummary($user);

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
                    'status' => strtoupper($tx->status ?: 'AVAILABLE'),
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
                    'status' => strtoupper($tx->status ?: 'REDEEMED'),
                ];
            });

            $adjusted = $transactions->whereIn('transaction_type', ['manual_added', 'manual_removed', 'adjustment'])->values()->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'date' => $tx->created_at ? $tx->created_at->format('M d, Y') : '',
                    'time' => $tx->created_at ? $tx->created_at->format('h:i A') : '',
                    'reason' => $tx->reason ?: 'Manual Adjustment',
                    'details' => $tx->reason_details ?: '',
                    'points' => ($tx->points > 0 ? '+' : '') . number_format($tx->points),
                    'status' => strtoupper($tx->status ?: 'AVAILABLE'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'available_points' => (int) $summary['available_points'],
                    'pending_points' => (int) $summary['pending_points'],
                    'lifetime_earned' => (int) $summary['lifetime_earned'],
                    'points_redeemed' => (int) $summary['redeemed_points'],
                    'points_expired' => (int) $summary['expired_points'],
                    'dollar_value' => $summary['dollar_value'],
                    'current_tier' => $summary['current_tier'],
                    'is_locked' => (bool) $summary['is_locked'],
                    'next_tier' => $summary['next_tier'] ?? null,
                    'target_points' => (int) ($summary['target_points'] ?? 0),
                    'progress_percent' => (float) ($summary['progress_percent'] ?? 0),
                    'tiers' => collect($summary['active_tiers'] ?? [])->map(fn ($t) => [
                        'name' => $t['name'] ?? '',
                        'min_points' => (int) ($t['min_points'] ?? 0),
                    ])->values()->all(),
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
            $type = $points >= 0 ? 'manual_added' : 'manual_removed';

            $success = \App\Services\LoyaltyService::adjustPoints(
                $user->id,
                abs($points),
                $type,
                $validated['reason'],
                null,
                'available',
                $validated['details'] ?? 'Admin adjustment',
                null,
                $admin ? $admin->id : null
            );

            if ($success) {
                UserAdminChange::create([
                    'user_id' => $user->id,
                    'admin_id' => $admin ? $admin->id : null,
                    'actor_name' => $admin ? $admin->name : 'Administrator',
                    'title' => 'Loyalty Points Adjusted',
                    'description' => ($points > 0 ? "Added {$points} points" : "Deducted " . abs($points) . " points") . " - Reason: " . $validated['reason'],
                    'changed_fields' => 'Loyalty Points',
                    'before_value' => (string) ($user->loyalty_points - $points),
                    'after_value' => (string) $user->fresh()->loyalty_points,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Loyalty points adjusted successfully',
                'data' => [
                    'loyalty_points' => $user->fresh()->loyalty_points,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update customer loyalty program status (activate / deactivate / suspend)
     */
    public function updateLoyaltyStatus(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $validated = $request->validate([
                'status' => 'required|string|in:active,inactive,suspended,deactivated',
                'reason' => 'nullable|string|max:500',
                'freeze_points' => 'nullable|boolean',
            ]);

            $newStatus = strtolower($validated['status']);
            $reason = $validated['reason'] ?: 'Loyalty program status updated by administrator';

            $user->membership_status = $newStatus === 'active' ? 'Active' : ($newStatus === 'suspended' ? 'Suspended' : 'Inactive');
            $user->save();

            $balance = \App\Services\LoyaltyService::getOrCreateBalance($user);
            $balance->is_locked = in_array($newStatus, ['suspended', 'inactive', 'deactivated']) || !empty($validated['freeze_points']);
            $balance->locked_reason = $balance->is_locked ? $reason : null;
            $balance->save();

            $admin = $request->user();
            try {
                UserAdminChange::create([
                    'user_id' => $user->id,
                    'admin_id' => $admin ? $admin->id : null,
                    'actor_name' => $admin ? $admin->name : 'Administrator',
                    'title' => 'Loyalty Program Status Changed',
                    'description' => 'Loyalty status set to ' . ucfirst($newStatus) . ". Reason: {$reason}",
                    'changed_fields' => 'Loyalty Status',
                    'after_value' => ucfirst($newStatus),
                ]);
            } catch (\Throwable $ex) {
                // Log notice
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer loyalty status updated to ' . ucfirst($newStatus) . ' successfully.',
                'data' => [
                    'loyalty_status' => $newStatus,
                    'is_locked' => $balance->is_locked,
                    'user' => $user,
                ],
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
                    'type' => strtolower((string) ($gc->giftcard_type ?? '')) === 'physical' ? 'physical' : 'digital',
                    'display_status' => $this->giftCardDisplayStatus($gc),
                    'recipient_email' => $gc->recipient_email,
                    'sender_name' => $gc->sender_name ?: 'Store Admin',
                    'expires_at' => $gc->expires_at ? Carbon::parse($gc->expires_at)->format('M d, Y') : 'No Expiry',
                    'created_at' => $gc->created_at ? $gc->created_at->format('M d, Y') : '',
                ];
            });

            $totalCards = $giftCards->count();
            $activeBalance = $giftCards->where('status', 'active')->sum('current_balance');
            $redeemedCount = $giftCards->where('status', 'redeemed')->count();

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 4);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
                'stats' => [
                    'total_cards' => $totalCards,
                    'active_balance' => (float) $activeBalance,
                    'redeemed_count' => $redeemedCount,
                    'by_status' => collect(['Active', 'Partially Redeemed', 'Pending', 'Expired', 'Voided'])
                        ->mapWithKeys(fn ($s) => [$s => $giftCards->filter(fn ($g) => $this->giftCardDisplayStatus($g) === $s)->count()])
                        ->all(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Slice an already-formatted collection using ?page & ?per_page.
     * Returns [pageRows, meta].
     */
    private function paginateRows(Request $request, $rows, int $defaultPerPage): array
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', $defaultPerPage)));
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return [
            $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Normalise a gift card into one of: Active, Partially Redeemed, Pending, Expired, Voided.
     */
    private function giftCardDisplayStatus($gc): string
    {
        $st = strtolower(trim((string) $gc->status));
        if (in_array($st, ['voided', 'void', 'disabled', 'cancelled', 'canceled', 'blocked'], true)) {
            return 'Voided';
        }
        if ($st === 'pending' || $st === 'scheduled') {
            return 'Pending';
        }
        if ($st === 'expired' || ($gc->expires_at && Carbon::parse($gc->expires_at)->isPast())) {
            return 'Expired';
        }
        $initial = (float) $gc->initial_balance;
        $current = (float) $gc->current_balance;
        if ($st === 'redeemed' || ($initial > 0 && $current <= 0)) {
            return 'Partially Redeemed';
        }
        if ($initial > 0 && $current < $initial) {
            return 'Partially Redeemed';
        }
        return 'Active';
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
                     'messages_count' => $tkt->replies->count() + 1,
                     'status_key' => $st,
                ];
            });

            $keyOf = function ($row) {
                $s = str_replace([' ', '-'], '_', strtolower((string) ($row['status_key'] ?? '')));
                if (in_array($s, ['resolved', 'closed'], true)) return 'resolved';
                if ($s === 'in_progress') return 'in_progress';
                return 'open';
            };
            $stats = [
                'total' => $formatted->count(),
                'open' => $formatted->filter(fn ($r) => $keyOf($r) === 'open')->count(),
                'in_progress' => $formatted->filter(fn ($r) => $keyOf($r) === 'in_progress')->count(),
                'resolved' => $formatted->filter(fn ($r) => $keyOf($r) === 'resolved')->count(),
            ];

            $statusFilter = strtolower((string) $request->query('status', 'all'));
            if (in_array($statusFilter, ['open', 'in_progress', 'resolved'], true)) {
                $formatted = $formatted->filter(fn ($r) => $keyOf($r) === $statusFilter)->values();
            }

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 4);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'stats' => $stats,
                'meta' => $meta,
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
                'kind' => 'email',
                'title' => $user->email_verified_at ? 'Email Verified' : 'Email Verification Pending',
                'description' => $user->email_verified_at ? "Customer's email address has been successfully verified." : "Customer's email address has not been verified yet.",
                'time' => ($user->email_verified_at ?: $user->created_at) ? Carbon::parse($user->email_verified_at ?: $user->created_at)->format('h:i A') : '',
                'timestamp' => ($user->email_verified_at ?: $user->created_at) ? Carbon::parse($user->email_verified_at ?: $user->created_at)->toIso8601String() : null,
                'meta_label' => $user->email_verified_at ? 'Verified by System' : 'Awaiting Customer',
                'chip_value' => $user->email,
                'chip_label' => 'Email Address',
                'chip_type' => 'email',
                'reason' => null,
            ];

            // Add phone verification event (real phone_verified_at timestamp)
            $phoneVerified = !empty($user->phone) && !empty($user->phone_verified_at);
            $phoneWhen = $user->phone_verified_at ?: $user->created_at;
            $events[] = [
                'id' => 'ver-phone',
                'type' => 'Phone Verification',
                'status' => $phoneVerified ? 'VERIFIED' : 'PENDING',
                'statusColor' => $phoneVerified ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400',
                'date' => $phoneWhen ? Carbon::parse($phoneWhen)->format('M d, Y') : '',
                'verifier' => 'SMS Verification',
                'details' => $user->phone ?: 'Not provided',
                'kind' => 'phone',
                'title' => $phoneVerified ? 'Phone Number Verified' : (empty($user->phone) ? 'No Phone Number Provided' : 'Phone Verification Pending'),
                'description' => $phoneVerified ? "Customer's phone number has been successfully verified." : (empty($user->phone) ? 'No phone number has been provided by the customer.' : "Customer's phone number has not been verified yet."),
                'time' => $phoneWhen ? Carbon::parse($phoneWhen)->format('h:i A') : '',
                'timestamp' => $phoneWhen ? Carbon::parse($phoneWhen)->toIso8601String() : null,
                'meta_label' => $phoneVerified ? 'Verified by System' : 'Awaiting Customer',
                'chip_value' => $user->phone ?: 'Not provided',
                'chip_label' => 'Phone Number',
                'chip_type' => 'phone',
                'reason' => null,
                'status_key' => $phoneVerified ? 'verified' : 'pending',
            ];

            foreach ($verifications as $v) {
                $stl = strtolower((string) $v->status);
                $isVerified = in_array($stl, ['verified', 'approved']);
                $isFailed = in_array($stl, ['failed', 'rejected', 'declined']);
                $docKind = (stripos((string) $v->document_type, 'address') !== false || stripos((string) $v->document_type, 'utility') !== false || stripos((string) $v->document_type, 'bill') !== false) ? 'address' : 'identity';
                $docLabel = $v->document_type ?: 'Identity';

                // 1) Submission event (always)
                $events[] = [
                    'id' => 'ver-doc-sub-' . $v->id,
                    'type' => $docLabel . ' Document Verification',
                    'status' => 'PENDING REVIEW',
                    'statusColor' => 'bg-amber-500/10 text-amber-400',
                    'date' => $v->created_at ? $v->created_at->format('M d, Y') : '',
                    'verifier' => 'Customer',
                    'details' => 'Document uploaded.',
                    'kind' => $docKind,
                    'title' => ucfirst($docKind) . ' Verification Submitted',
                    'description' => 'Customer submitted ' . strtolower($docKind) . ' document for verification.',
                    'time' => $v->created_at ? $v->created_at->format('h:i A') : '',
                    'timestamp' => $v->created_at ? $v->created_at->toIso8601String() : null,
                    'meta_label' => 'Submitted by Customer',
                    'chip_value' => $docLabel,
                    'chip_label' => 'Document Type',
                    'chip_type' => 'document',
                    'reason' => null,
                    'status_key' => 'pending',
                ];

                // 2) Decision event (once an admin has reviewed it)
                if ($isVerified || $isFailed) {
                    $decidedAt = $v->updated_at ?: $v->created_at;
                    $events[] = [
                        'id' => 'ver-doc-' . $v->id,
                        'type' => $docLabel . ' Document Verification',
                        'status' => strtoupper($v->status),
                        'statusColor' => $isVerified ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400',
                        'date' => $decidedAt ? $decidedAt->format('M d, Y') : '',
                        'verifier' => 'Compliance Team',
                        'details' => $v->notes ?: ($isVerified ? 'Document verified.' : 'Document rejected.'),
                        'kind' => $docKind,
                        'title' => ucfirst($docKind) . ' ' . ($isVerified ? 'Verified' : 'Verification Failed'),
                        'description' => $isVerified ? "Customer's " . strtolower($docKind) . ' document has been verified.' : ucfirst($docKind) . ' could not be verified.',
                        'time' => $decidedAt ? $decidedAt->format('h:i A') : '',
                        'timestamp' => $decidedAt ? $decidedAt->toIso8601String() : null,
                        'meta_label' => $isVerified ? 'Verified by Admin' : 'Reviewed by Admin',
                        'chip_value' => $docLabel,
                        'chip_label' => 'Document Type',
                        'chip_type' => 'document',
                        'reason' => $isFailed ? ($v->notes ?: 'Document rejected') : null,
                        'status_key' => $isVerified ? 'verified' : 'failed',
                    ];
                }
            }

            usort($events, fn ($a, $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));

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

                $dItems = is_array($dsp->items) ? $dsp->items : [];
                $itemsCount = 0;
                $thumb = null;
                foreach ($dItems as $di) {
                    $itemsCount += is_array($di) ? max(1, (int) ($di['quantity'] ?? $di['qty'] ?? 1)) : 1;
                    if (!$thumb && is_array($di)) {
                        $img = $di['image'] ?? $di['product_image'] ?? $di['thumbnail'] ?? $di['image_url'] ?? null;
                        if (is_string($img) && $img !== '') {
                            $thumb = preg_match('#^https?://#', $img) ? $img : asset('storage/' . ltrim($img, '/'));
                        }
                    }
                }
                if (!$thumb && is_array($dsp->evidence)) {
                    foreach ($dsp->evidence as $ev) {
                        $p = is_array($ev) ? ($ev['url'] ?? $ev['path'] ?? null) : $ev;
                        if (is_string($p) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $p)) {
                            $thumb = preg_match('#^https?://#', $p) ? $p : asset('storage/' . ltrim($p, '/'));
                            break;
                        }
                    }
                }

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
                    'product_image' => $thumb,
                    'items_count' => $itemsCount,
                    'reason' => $dsp->dispute_type_name ?: ($dsp->type ?: 'Order Dispute'),
                    'status_key' => in_array($st, ['resolved']) ? 'resolved' : (in_array($st, ['closed']) ? 'closed' : (in_array($st, ['under review', 'under_review', 'in_progress', 'in progress']) ? 'under_review' : 'open')),
                ];
            });

            $totalCount = $disputes->count();
            $closedCount = $disputes->filter(fn ($d) => strtolower((string) $d->status) === 'closed')->count();
            $lifetimeRefund = (float) $disputes->filter(fn ($d) => in_array(strtolower((string) $d->status), ['resolved', 'closed']))->sum('amount');
            $openCount = $disputes->whereIn('status', ['OPEN', 'open'])->count();
            $underReviewCount = $disputes->whereIn('status', ['UNDER REVIEW', 'under review', 'in_progress'])->count();
            $resolvedCount = $disputes->whereIn('status', ['RESOLVED', 'resolved', 'CLOSED', 'closed'])->count();
            $currentAmount = $disputes->whereIn('status', ['OPEN', 'open', 'UNDER REVIEW', 'under review'])->sum('amount');
            $lifetimeDisputed = $disputes->sum('amount');

            $statusCounts = [
                'open' => $formatted->where('status_key', 'open')->count(),
                'under_review' => $formatted->where('status_key', 'under_review')->count(),
                'resolved' => $formatted->where('status_key', 'resolved')->count(),
                'closed' => $formatted->where('status_key', 'closed')->count(),
            ];

            $statusFilter = strtolower((string) $request->query('status', 'all'));
            if (isset($statusCounts[$statusFilter])) {
                $formatted = $formatted->where('status_key', $statusFilter)->values();
            }

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 6);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
                'stats' => [
                    'counts' => $statusCounts,
                    'total' => $totalCount,
                    'open' => $openCount,
                    'under_review' => $underReviewCount,
                    'resolved' => $resolvedCount,
                    'closed' => $closedCount,
                    'lifetime_refund_amount' => $lifetimeRefund,
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
                    'image' => $rpt->product_image ?: null,
                ];
            });

            $total = $reports->count();
            $underReview = $reports->whereIn('status', ['UNDER REVIEW', 'under review', 'pending'])->count();
            $actionTaken = $reports->whereIn('status', ['ACTION TAKEN', 'action taken'])->count();
            $noViolation = $reports->whereIn('status', ['NO VIOLATION', 'no violation'])->count();

            $reportKey = function ($row) {
                $s = strtolower((string) ($row['status'] ?? ''));
                if (str_contains($s, 'action')) return 'action_taken';
                if (str_contains($s, 'no') || str_contains($s, 'violation') || str_contains($s, 'dismiss')) return 'no_violation';
                return 'under_review';
            };
            $statusCounts = ['under_review' => 0, 'action_taken' => 0, 'no_violation' => 0];
            foreach ($formatted as $row) {
                $statusCounts[$reportKey($row)]++;
            }

            $statusFilter = strtolower((string) $request->query('status', 'all'));
            if (isset($statusCounts[$statusFilter])) {
                $formatted = $formatted->filter(fn ($r) => $reportKey($r) === $statusFilter)->values();
            }

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 6);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
                'stats' => [
                    'counts' => $statusCounts,
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

            // Per-customer view: coupons this customer has redeemed (from their orders) plus
            // coupons that are currently live and could still be used by them.
            $userUses = [];
            EcommerceOrder::where('user_id', $user->id)->get(['id', 'metadata'])->each(function ($o) use (&$userUses) {
                $md = is_array($o->metadata) ? $o->metadata : (json_decode((string) $o->metadata, true) ?: []);
                $code = $md['coupon_code'] ?? ($md['coupon']['code'] ?? null);
                if ($code) {
                    $k = strtoupper((string) $code);
                    $userUses[$k] = ($userUses[$k] ?? 0) + 1;
                }
            });

            $coupons = EcommerceCoupon::orderBy('id', 'asc')->get()->filter(function ($c) use ($userUses) {
                if (isset($userUses[strtoupper((string) $c->code)])) {
                    return true;
                }
                if (!$c->is_active) {
                    return false;
                }
                if ($c->expires_at && Carbon::parse($c->expires_at)->isPast()) {
                    return false;
                }
                if ($c->usage_limit !== null && (int) $c->usage_limit > 0 && (int) $c->used_count >= (int) $c->usage_limit) {
                    return false;
                }
                return true;
            })->values();

            $hexColors = [
                '#ef0b6e',
                '#6366f1',
                '#f97316',
                '#0070f3',
                '#00b074',
                '#00a8cc',
            ];

            $formatted = $coupons->values()->map(function ($c, $idx) use ($hexColors, $userUses) {
                $myUses = (int) ($userUses[strtoupper((string) $c->code)] ?? 0);
                $perCustomer = (int) (($c->metadata['per_customer_limit'] ?? 0) ?: 0);
                $limitForUser = $perCustomer > 0 ? $perCustomer : ((int) ($c->usage_limit ?: 0));
                $isPercent = $c->discount_type === 'percentage' || str_contains($c->discount_type ?? '', 'percent');
                $isFreeShip = str_contains(strtolower($c->code), 'ship') || str_contains(strtolower($c->title ?? ''), 'shipping');

                $discount = $isFreeShip ? 'FREE' : ($isPercent ? ((int)$c->discount_value . '%') : ('$' . (int)$c->discount_value));
                $discountSub = $isFreeShip ? 'SHIPPING' : 'OFF';

                $metaStatus = $c->metadata['status'] ?? null;
                if ($metaStatus) {
                    $status = ucfirst(strtolower($metaStatus));
                } elseif ($c->expires_at && Carbon::parse($c->expires_at)->isPast()) {
                    $status = 'Expired';
                } elseif ($c->starts_at && Carbon::parse($c->starts_at)->isFuture()) {
                    $status = 'Scheduled';
                } else {
                    $status = 'Active';
                }

                $statusColor = 'bg-[#dcfce7] text-[#15803d]';
                if ($status === 'Expired') {
                    $statusColor = 'bg-[#ffe4e6] text-[#e11d48]';
                } elseif ($status === 'Scheduled') {
                    $statusColor = 'bg-[#e0e7ff] text-[#4338ca]';
                }

                $metaColor = $c->metadata['color'] ?? $hexColors[$idx % count($hexColors)];

                return [
                    'id' => $c->id,
                    'discount' => $discount,
                    'discountSub' => $discountSub,
                    'code' => $c->code,
                    'status' => $status,
                    'statusColor' => $statusColor,
                    'appliesTo' => $c->metadata['applies_to'] ?? ($c->applies_to ?: 'All products'),
                    'minSpend' => '$' . number_format((float) ($c->min_order_amount ?? 0), 2),
                    'validFrom' => $c->metadata['valid_from'] ?? ($c->starts_at ? Carbon::parse($c->starts_at)->format('M d, Y') : ($c->created_at ? $c->created_at->format('M d, Y') : '')),
                    'validTo' => $c->metadata['valid_to'] ?? ($c->expires_at ? Carbon::parse($c->expires_at)->format('M d, Y') : 'No Expiry'),
                    'usedCount' => $myUses,
                    'usageLimit' => $limitForUser,
                    'usage' => $myUses . ' / ' . ($limitForUser > 0 ? $limitForUser : '∞') . ' Used',
                    'ribbonBg' => $metaColor,
                    'color' => $metaColor,
                ];
            });

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 6);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
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

            $userOrderNumbers = EcommerceOrder::where('user_id', $user->id)
                ->pluck('order_number')
                ->filter()
                ->values()
                ->all();

            $donations = Donation::where(function ($q) use ($user, $userOrderNumbers) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('donations', 'user_id')) {
                    $q->where('user_id', $user->id);
                }
                if (!empty($user->email)) {
                    $q->orWhere('donor_email', $user->email);
                }
                if (!empty($userOrderNumbers)) {
                    $q->orWhereIn('order_id', $userOrderNumbers);
                }
            })
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $donations->map(function ($d) {
                $paymentLabel = trim(($d->payment_method_brand ?: 'Card') . ($d->payment_method_details ? ' (' . $d->payment_method_details . ')' : ''));
                return [
                    'id' => $d->id,
                    'order_id' => $d->order_id,
                    'date' => $d->created_at ? $d->created_at->format('M d, Y') : '',
                    'time' => $d->created_at ? $d->created_at->format('h:i A') : '',
                    'organization' => $d->charity_name,
                    'charity' => $d->charity_name,
                    'campaign' => $d->charity_category ?: 'Charity Initiative',
                    'category' => $d->charity_category ?: 'Charity Initiative',
                    'logo_type' => $d->charity_logo_type ?: 'generic_charity',
                    'purpose' => $d->charity_category ?: 'Charity Initiative',
                    'amount' => (float) $d->amount,
                    'payment_method' => $paymentLabel,
                    'payment' => $paymentLabel,
                    'status' => $d->status ?: 'Completed',
                    'refund_reason' => $d->refund_reason,
                ];
            });

            $activeDonations = $donations->filter(fn ($d) => !in_array(strtolower((string) $d->status), ['refunded', 'reversed', 'failed']));
            $totalAmount = (float) $activeDonations->sum('amount');
            $donationCount = $activeDonations->count();
            $organizationsSupported = $activeDonations->pluck('charity_name')->filter()->unique()->count();

            $statusFilter = strtolower((string) $request->query('status', 'all'));
            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $formatted = $formatted->filter(fn ($r) => strtolower((string) ($r['status'] ?: 'Completed')) === $statusFilter)->values();
            }

            [$pageRows, $meta] = $this->paginateRows($request, $formatted, 6);

            return response()->json([
                'success' => true,
                'data' => $pageRows,
                'meta' => $meta,
                'stats' => [
                    'total_amount' => $totalAmount,
                    'donation_count' => $donationCount,
                    'organizations_supported' => $organizationsSupported,
                    'last_donation_date' => optional($activeDonations->first()?->created_at)->format('M d, Y') ?: '',
                    'total_donated' => $totalAmount,
                    'count' => $donationCount,
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
                $ext = strtolower($f->file_type ?: pathinfo((string) $f->file_name, PATHINFO_EXTENSION));
                $ext = ltrim($ext, '.');
                $fileUrl = $f->file_path ? asset('storage/' . ltrim($f->file_path, '/')) : null;
                $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg']);
                $dimensions = null;
                if ($isImage && $ext !== 'svg' && $f->file_path) {
                    try {
                        $abs = storage_path('app/public/' . ltrim($f->file_path, '/'));
                        if (is_file($abs) && ($dim = @getimagesize($abs))) {
                            $dimensions = $dim[0] . ' x ' . $dim[1] . ' px';
                        }
                    } catch (\Throwable $e) {
                    }
                }
                $bytes = (int) $f->size_bytes;
                return [
                    'file_ext' => $ext,
                    'size_bytes' => $bytes,
                    'size_label' => $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B'),
                    'dimensions' => $dimensions,
                    'preview' => $isImage ? $fileUrl : null,
                    'download_url' => $fileUrl,
                    'download_id' => $f->id,
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
                    'storage_used_bytes' => (int) $files->sum('size_bytes'),
                    'storage_total_bytes' => (int) env('CUSTOMER_STORAGE_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),
                    'type_counts' => $files->groupBy(fn ($x) => strtolower(ltrim($x->file_type ?: pathinfo((string) $x->file_name, PATHINFO_EXTENSION), '.')))->map->count(),
                    'categories' => $files->groupBy('category')->map->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function centralAuthInternalBaseUrl(): string
    {
        $base = rtrim((string) (
            config('services.central_auth.internal_url')
            ?: config('services.central_auth.api_url')
            ?: config('services.central_auth.url')
        ), '/');

        if ($base === '') {
            return '';
        }

        return str_ends_with($base, '/api') ? $base : $base . '/api';
    }

    /**
     * 17. List user's Central Auth devices
     */
    public function getDevices(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $base = $this->centralAuthInternalBaseUrl();
            $secret = (string) config('services.internal_notifications.secret');

            if ($base === '' || $secret === '') {
                return response()->json(['success' => false, 'message' => 'Central Auth internal configuration missing.'], 500);
            }

            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                ->get($base . '/internal/users/devices', ['email' => $user->email]);

            return response()->json(
                $response->json() ?? ['success' => false, 'message' => 'Invalid response from Central Auth.'],
                $response->status()
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 18. Toggle trust status for a user's Central Auth device
     */
    public function toggleDeviceTrust(Request $request, $id, $deviceId)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $base = $this->centralAuthInternalBaseUrl();
            $secret = (string) config('services.internal_notifications.secret');

            if ($base === '' || $secret === '') {
                return response()->json(['success' => false, 'message' => 'Central Auth internal configuration missing.'], 500);
            }

            $payload = array_filter(
                $request->only(['is_trusted', 'reset_risk', 'reset_user_risk']),
                fn ($v) => $v !== null
            );

            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                ->post($base . '/internal/users/devices/' . urlencode((string) $deviceId) . '/trust', $payload);

            return response()->json(
                $response->json() ?? ['success' => false, 'message' => 'Invalid response from Central Auth.'],
                $response->status()
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
