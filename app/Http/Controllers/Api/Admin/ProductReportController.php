<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\ModerationActivityLog;
use App\Models\Product;
use App\Models\ProductReport;
use App\Models\User;
use App\Services\Moderation\ModerationLogger;
use Illuminate\Http\Request;

/**
 * Reported products.
 *
 * Customers who bought a product can report it (reason required, comment optional). Reports
 * are private to admins and move Pending → Under Review → Resolved / Dismissed. A customer
 * can have only one open report per product. Every admin action is written to the
 * moderation log.
 */
class ProductReportController extends Controller
{
    /** Order states that count as a purchase. */
    private const PURCHASED_ORDER_STATUSES = ['confirmed', 'processing', 'in_production', 'shipped', 'delivered', 'completed', 'complete'];
    private const PAID_PAYMENT_STATUSES = ['paid', 'partially_refunded', 'refunded'];

    private function findReport($id): ProductReport
    {
        return ProductReport::where(function ($q) use ($id) {
            if (ctype_digit((string) $id)) {
                $q->where('id', (int) $id);
            }
            $q->orWhere('report_code', $id)->orWhere('report_code', '#' . $id);
        })->firstOrFail();
    }

    private function log(Request $request, ProductReport $report, string $action, array $details = []): void
    {
        ModerationLogger::record($request, ModerationActivityLog::FEATURE_PRODUCT_REPORT, 'product_report', (int) $report->id,
            $action, $details, $report->product_id ? (int) $report->product_id : null);
    }

    private static function stats($reports): array
    {
        $count = fn (string $status) => $reports->filter(fn ($r) => strcasecmp((string) $r->status, $status) === 0)->count();

        return [
            'all' => $reports->count(),
            'pending' => $count(ProductReport::STATUS_PENDING),
            'under_review' => $count(ProductReport::STATUS_UNDER_REVIEW),
            'resolved' => $count(ProductReport::STATUS_RESOLVED),
            'dismissed' => $count(ProductReport::STATUS_DISMISSED),
        ];
    }

    public static function statsFor($reports): array
    {
        return self::stats($reports);
    }

    public function index(Request $request)
    {
        $query = ProductReport::query();

        if ($request->filled('status') && ! in_array($request->input('status'), ['All', 'all', 'All Status'], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('reason') && ! in_array($request->input('reason'), ['All', 'all'], true)) {
            $query->where('reason', $request->input('reason'));
        }

        if ($request->has('search') && ! empty($request->input('search'))) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('report_code', 'like', "%{$search}%")
                  ->orWhere('product_name', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('issue', 'like', "%{$search}%");
            });
        }

        $allReports = ProductReport::query()->get(['id', 'status']);
        $filteredReports = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'stats' => self::stats($allReports),
            'statuses' => ProductReport::STATUSES,
            'reasons' => ProductReport::REASONS,
            'data' => $filteredReports->map(fn (ProductReport $report) => self::transformReport($report))->values(),
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => self::transformReport($this->findReport($id)),
        ]);
    }

    public function update(Request $request, $id)
    {
        $report = $this->findReport($id);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:' . implode(',', ProductReport::STATUSES)],
            'admin_feedback' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $currentStatus = strtolower(trim((string) $report->status));
        $statusChanging = isset($validated['status']) && strcasecmp($validated['status'], (string) $report->status) !== 0;

        if ($statusChanging) {
            if (in_array($currentStatus, ProductReport::FINAL_STATUSES, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report status is final and cannot be modified once resolved or dismissed.',
                ], 422);
            }

            $adminName = ModerationLogger::adminName($request);
            $history = collect($report->status_history ?? [])
                ->map(fn ($h) => array_merge($h, ['current' => false]))
                ->reject(fn ($h) => ($h['date'] ?? null) === '-')
                ->values()
                ->all();
            $history[] = [
                'step' => $validated['status'],
                'date' => now()->format('M d, Y h:i A'),
                'description' => "Status changed to {$validated['status']} by {$adminName}"
                    . (! empty($validated['note']) ? ': ' . $validated['note'] : ''),
                'completed' => true,
                'current' => true,
            ];

            $this->log($request, $report, 'status_changed', array_filter([
                'from' => $report->status,
                'to' => $validated['status'],
                'note' => $validated['note'] ?? null,
            ]));

            $report->status = $validated['status'];
            $report->status_history = $history;
        }

        if (isset($validated['admin_feedback'])) {
            $report->admin_feedback = array_merge($report->admin_feedback ?? [], $validated['admin_feedback']);
            $this->log($request, $report, 'feedback_updated', ['feedback' => $validated['admin_feedback']]);
        }

        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Report updated successfully',
            'data' => self::transformReport($report),
        ]);
    }

    public function reply(Request $request, $id)
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'resolution' => 'nullable|string|max:1000',
            'estimated_resolution_date' => 'nullable|string|max:100',
        ]);

        $report = $this->findReport($id);

        $feedback = [
            'author' => 'Quality Assurance Team',
            'role' => 'admin',
            'admin_name' => ModerationLogger::adminName($request),
            'date' => now()->format('M d, Y h:i A'),
            'message' => $request->input('message'),
            'resolution' => $request->input('resolution', 'Under investigation'),
            'estimated_resolution_date' => $request->input('estimated_resolution_date', now()->addDays(3)->format('M d, Y')),
        ];

        $report->admin_feedback = $feedback;
        $report->save();

        $this->log($request, $report, 'reply_sent', [
            'message' => $feedback['message'],
            'resolution' => $feedback['resolution'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reply posted successfully',
            'data' => self::transformReport($report),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        try {
            $report = $this->findReport($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product report not found',
            ], 404);
        }

        $this->log($request, $report, 'deleted', [
            'report_code' => $report->report_code,
            'customer' => $report->customer_name,
            'reason' => $report->reason ?? $report->issue,
            'status' => $report->status,
        ]);

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product report deleted successfully',
        ]);
    }

    /** Map a reason key or label ("Counterfeit concerns", "counterfeit") to its key. */
    private static function reasonKey(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        foreach (ProductReport::REASONS as $key => $label) {
            if ($value === $key || $value === strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    /** The customer's purchase of the product, if any (by account or account email). */
    private function findPurchase(User $user, Product $product, ?string $orderNumber): ?EcommerceOrder
    {
        $email = strtolower(trim((string) $user->email));
        $statuses = implode(',', array_fill(0, count(self::PURCHASED_ORDER_STATUSES), '?'));
        $payments = implode(',', array_fill(0, count(self::PAID_PAYMENT_STATUSES), '?'));

        $query = EcommerceOrder::query()
            ->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id);
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->where(function ($q) use ($statuses, $payments) {
                $q->whereRaw("LOWER(status) IN ({$statuses})", self::PURCHASED_ORDER_STATUSES)
                  ->orWhereRaw("LOWER(COALESCE(payment_status, '')) IN ({$payments})", self::PAID_PAYMENT_STATUSES);
            })
            ->whereHas('items', function ($q) use ($product) {
                $q->where(function ($iq) use ($product) {
                    $iq->where('product_id', $product->id);
                    if (! empty($product->sku)) {
                        $iq->orWhere('product_sku', $product->sku);
                    }
                });
            });

        $cleanNum = $orderNumber ? ltrim(trim($orderNumber), '#') : null;
        if ($cleanNum) {
            $preferred = (clone $query)->where(function ($q) use ($cleanNum) {
                $q->where('order_number', $cleanNum)->orWhere('order_number', '#' . $cleanNum);
            })->latest('id')->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return $query->latest('id')->first();
    }

    public function submit(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'reason' => 'login_required',
                'message' => 'Please sign in to report a product.',
            ], 401);
        }

        $validated = $request->validate([
            'product_id' => ['required'],
            'reason' => ['nullable', 'string', 'max:100'],
            'issue' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'order_number' => ['nullable', 'string', 'max:100'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240'],
        ]);

        $reasonKey = self::reasonKey($validated['reason'] ?? $validated['issue'] ?? null);
        if (! $reasonKey) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose a reason for your report.',
                'errors' => ['reason' => ['Choose one of: ' . implode(', ', ProductReport::REASONS) . '.']],
                'reasons' => ProductReport::REASONS,
            ], 422);
        }

        $pid = trim((string) $validated['product_id']);
        $product = ctype_digit($pid) ? Product::find((int) $pid) : Product::where('sku', $pid)->first();
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $order = $this->findPurchase($user, $product, $validated['order_number'] ?? null);
        if (! $order) {
            return response()->json([
                'success' => false,
                'reason' => 'not_purchased',
                'message' => 'Only customers who purchased this product can report it.',
            ], 403);
        }

        $openReport = ProductReport::where('product_id', $product->id)
            ->where(function ($q) use ($user) {
                $email = strtolower(trim((string) $user->email));
                $q->where('user_id', $user->id);
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->whereIn('status', ProductReport::OPEN_STATUSES)
            ->first();
        if ($openReport) {
            return response()->json([
                'success' => false,
                'reason' => 'already_reported',
                'message' => 'You already have an open report for this product (' . $openReport->report_code . '). We will update you there.',
                'existing_report_code' => $openReport->report_code,
            ], 409);
        }

        $uploadedImages = [];
        foreach ((array) $request->file('photos', []) as $file) {
            if ($file && $file->isValid()) {
                $uploadedImages[] = '/storage/' . $file->store('reported-products', 'public');
            }
        }

        $reasonLabel = ProductReport::REASONS[$reasonKey];
        $comment = trim((string) ($validated['comment'] ?? $validated['description'] ?? ''));
        $now = now()->format('M d, Y h:i A');

        $report = ProductReport::create([
            'report_code' => 'PENDING',
            'product_id' => $product->id,
            'user_id' => $user->id,
            'product_name' => $product->name,
            'product_image' => $uploadedImages[0] ?? ($product->thumbnail ?? $product->image ?? ''),
            'customer_name' => $user->name ?: 'Customer',
            'customer_email' => $user->email,
            'customer_phone' => $validated['phone'] ?? ($user->phone ?? null),
            'order_number' => $order->order_number,
            'purchase_date' => optional($order->created_at)->format('M d, Y'),
            'purchase_location' => 'Online Store',
            'quantity' => 1,
            'issue' => $reasonLabel,
            'issue_type' => $reasonKey,
            'reason' => $reasonKey,
            'category' => 'Customer Report',
            'description' => $comment !== '' ? $comment : null,
            'status' => ProductReport::STATUS_PENDING,
            'attachments_count' => count($uploadedImages),
            'product_images' => $uploadedImages,
            'admin_feedback' => null,
            'customer_replies' => [],
            'status_history' => [
                [
                    'step' => 'Reported',
                    'date' => $now,
                    'description' => 'Reported by customer: ' . $reasonLabel,
                    'completed' => true,
                    'current' => false,
                ],
                [
                    'step' => ProductReport::STATUS_PENDING,
                    'date' => $now,
                    'description' => 'Waiting for an administrator to review',
                    'completed' => true,
                    'current' => true,
                ],
            ],
        ]);

        // The code comes from the row id, so concurrent submissions never share one.
        $report->report_code = '#RPTO' . str_pad((string) $report->id, 5, '0', STR_PAD_LEFT);
        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Thank you. Your report was submitted and is private to our administrators.',
            'data' => self::transformReport($report),
        ], 201);
    }

    public static function transformReport(ProductReport $report): array
    {
        $images = $report->product_images;
        if (is_string($images)) {
            $images = json_decode($images, true);
        }
        if (!is_array($images) || empty($images)) {
            $images = !empty($report->product_image) ? [$report->product_image] : [];
        }

        $resolveUrl = function ($path) {
            if (empty($path)) return '';
            $clean = trim($path);
            if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://') || str_starts_with($clean, 'data:')) {
                return $clean;
            }
            if (str_starts_with($clean, '/storage/')) {
                return asset($clean);
            }
            if (str_starts_with($clean, 'storage/')) {
                return asset('/' . $clean);
            }
            return $clean;
        };

        $resolvedProductImage = $resolveUrl($report->product_image);
        $resolvedImages = array_map($resolveUrl, $images);

        $adminFeedback = $report->admin_feedback;
        if (is_string($adminFeedback)) {
            $adminFeedback = json_decode($adminFeedback, true);
        }

        $customerReplies = $report->customer_replies;
        if (is_string($customerReplies)) {
            $customerReplies = json_decode($customerReplies, true);
        }
        if (!is_array($customerReplies)) {
            $customerReplies = [];
        }

        $statusHistory = $report->status_history;
        if (is_string($statusHistory)) {
            $statusHistory = json_decode($statusHistory, true);
        }
        $createdAtFormatted = optional($report->created_at)->format('M d, Y h:i A') ?? now()->format('M d, Y h:i A');
        $updatedAtFormatted = optional($report->updated_at)->format('M d, Y h:i A') ?? now()->format('M d, Y h:i A');
        $createdDateFormatted = optional($report->created_at)->format('M d, Y') ?? now()->format('M d, Y');
        $createdTimeFormatted = optional($report->created_at)->format('h:i A') ?? now()->format('h:i A');

        if (!is_array($statusHistory) || empty($statusHistory)) {
            $statusHistory = [
                ['step' => 'Reported', 'date' => $createdAtFormatted, 'description' => 'Reported by customer', 'completed' => true, 'current' => false],
                ['step' => 'Under Review', 'date' => $updatedAtFormatted, 'description' => 'Report is being reviewed', 'completed' => true, 'current' => true],
                ['step' => 'Resolution', 'date' => '-', 'description' => 'Awaiting resolution', 'completed' => false, 'current' => false]
            ];
        }

        $reportCode = $report->report_code ?? ('#RPTO' . str_pad($report->id, 5, '0', STR_PAD_LEFT));
        $customerName = $report->customer_name ?? ($report->user ? $report->user->name : 'Customer');
        $customerEmail = $report->customer_email ?? ($report->user ? $report->user->email : '');
        $customerPhone = $report->customer_phone ?? '';

        return [
            // Standard snake_case
            'id' => $report->id,
            'report_code' => $reportCode,
            'product_id' => $report->product_id,
            'user_id' => $report->user_id,
            'product' => $report->product_name ?? ($report->product ? $report->product->name : 'Product'),
            'product_name' => $report->product_name ?? ($report->product ? $report->product->name : 'Product'),
            'product_image' => $resolvedProductImage,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'order_number' => $report->order_number ?? 'N/A',
            'quantity' => $report->quantity ?? 1,
            'purchase_date' => $report->purchase_date ?? $createdDateFormatted,
            'purchase_location' => $report->purchase_location ?? 'Online Store',
            'date' => $createdDateFormatted,
            'created_at_time' => $createdTimeFormatted,
            'issue' => $report->issue ?? 'Product Quality',
            'issue_type' => $report->issue_type ?? $report->issue ?? 'Product Quality',
            'reason' => $report->reason,
            'reason_label' => $report->reason ? (ProductReport::REASONS[$report->reason] ?? $report->issue) : ($report->issue ?? null),
            'category' => $report->category ?? 'Customer Submission',
            'description' => $report->description,
            'status' => $report->status ?? ProductReport::STATUS_PENDING,
            'attachments_count' => $report->attachments_count ?? count($resolvedImages),
            'product_images' => $resolvedImages,
            'admin_feedback' => $adminFeedback,
            'customer_replies' => $customerReplies,
            'status_history' => $statusHistory,

            // CamelCase mappings for Admin Panel page
            'reportId' => $reportCode,
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'customerPhone' => $customerPhone,
            'customerSince' => optional($report->user ? $report->user->created_at : $report->created_at)->format('M d, Y') ?? $createdDateFormatted,
            'productName' => $report->product_name ?? ($report->product ? $report->product->name : 'Product'),
            'productImage' => $resolvedProductImage,
            'orderNumber' => $report->order_number ?? 'N/A',
            'orderDate' => $report->purchase_date ?? $createdDateFormatted,
            'categorySub' => $report->category ?? 'Customer Submission',
            'categoryColor' => 'text-amber-700 dark:text-amber-400',
            'categoryBg' => 'bg-amber-100 dark:bg-amber-950/60',
            'categoryIconType' => 'stitch',
            'attachmentsCount' => $report->attachments_count ?? count($resolvedImages),
            'productImages' => $resolvedImages,
            'dateReported' => $createdDateFormatted,
            'timeReported' => $createdTimeFormatted,
            'replies' => array_map(function($r) {
                return [
                    'id' => $r['id'] ?? 'r_' . uniqid(),
                    'author' => $r['user_name'] ?? $r['author'] ?? 'Customer',
                    'role' => (isset($r['author']) && str_contains(strtolower($r['author']), 'admin')) ? 'admin' : 'customer',
                    'timestamp' => $r['date'] ?? now()->format('M d, Y h:i A'),
                    'avatar' => strtoupper(substr($r['user_name'] ?? 'C', 0, 1)),
                    'avatarBg' => 'bg-slate-700',
                    'message' => $r['message'] ?? '',
                ];
            }, $customerReplies),
            'history' => array_map(function($h) {
                return [
                    'title' => $h['step'] ?? 'Step',
                    'date' => $h['date'] ?? '-',
                    'subtext' => $h['description'] ?? '',
                    'status' => !empty($h['completed']) ? ($h['current'] ? 'current' : 'completed') : 'pending',
                ];
            }, $statusHistory),
        ];
    }
}
