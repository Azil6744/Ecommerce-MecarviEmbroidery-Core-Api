<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductReport;
use Illuminate\Http\Request;

class ProductReportController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductReport::query();

        if ($request->has('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('report_code', 'like', "%{$search}%")
                  ->orWhere('product_name', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('issue', 'like', "%{$search}%");
            });
        }

        $allReports = ProductReport::query()->orderBy('created_at', 'desc')->get();
        $filteredReports = $query->orderBy('created_at', 'desc')->get();

        $stats = [
            'all' => $allReports->count(),
            'under_review' => $allReports->where('status', 'Under Review')->count(),
            'in_progress' => $allReports->where('status', 'In Progress')->count(),
            'resolved' => $allReports->where('status', 'Resolved')->count(),
            'closed' => $allReports->where('status', 'Closed')->count(),
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'data' => $filteredReports->map(fn (ProductReport $report) => self::transformReport($report))->values(),
        ]);
    }

    public function show($id)
    {
        $report = ProductReport::where('id', $id)
            ->orWhere('report_code', $id)
            ->orWhere('report_code', '#' . $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => self::transformReport($report)
        ]);
    }

    public function update(Request $request, $id)
    {
        $report = ProductReport::where('id', $id)
            ->orWhere('report_code', $id)
            ->orWhere('report_code', '#' . $id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:Under Review,In Progress,Resolved,Closed,Pending'],
            'admin_feedback' => ['nullable', 'array'],
        ]);

        if (isset($validated['status'])) {
            $currentStatus = strtolower(trim((string)$report->status));
            if (in_array($currentStatus, ['resolved', 'closed', 'declined', 'approved'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report status is final and cannot be modified once resolved or closed.'
                ], 422);
            }

            $report->status = $validated['status'];
            $history = $report->status_history ?? [];
            $history[] = [
                'step' => $validated['status'],
                'date' => now()->format('M d, Y h:i A'),
                'description' => 'Status updated by QA admin',
                'completed' => true,
                'current' => true,
            ];
            $report->status_history = $history;
        }

        if (isset($validated['admin_feedback'])) {
            $report->admin_feedback = array_merge($report->admin_feedback ?? [], $validated['admin_feedback']);
        }

        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Report status updated successfully',
            'data' => self::transformReport($report),
        ]);
    }

    public function reply(Request $request, $id)
    {
        $request->validate([
            'message' => 'required|string',
            'resolution' => 'nullable|string',
            'estimated_resolution_date' => 'nullable|string',
        ]);

        $report = ProductReport::where('id', $id)
            ->orWhere('report_code', $id)
            ->orWhere('report_code', '#' . $id)
            ->firstOrFail();

        $feedback = [
            'author' => 'Quality Assurance Team',
            'role' => 'admin',
            'date' => now()->format('M d, Y h:i A'),
            'message' => $request->input('message'),
            'resolution' => $request->input('resolution', 'Inspection & rework initiated'),
            'estimated_resolution_date' => $request->input('estimated_resolution_date', now()->addDays(3)->format('M d, Y')),
        ];

        $report->admin_feedback = $feedback;
        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Reply posted successfully',
            'data' => self::transformReport($report),
        ]);
    }

    public function destroy($id)
    {
        $report = ProductReport::where('id', $id)
            ->orWhere('report_code', $id)
            ->orWhere('report_code', '#' . $id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Product report not found',
            ], 404);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product report deleted successfully',
        ]);
    }

    public function submit(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'nullable',
            'product_name' => 'nullable|string',
            'full_name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string',
            'issue' => 'required|string',
            'description' => 'required|string',
            'order_number' => 'nullable|string',
            'date_received' => 'nullable|string',
            'purchase_location' => 'nullable|string',
            'photos.*' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:10240',
        ]);

        $uploadedImages = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file->isValid()) {
                    $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('reported-products', $filename, 'public');
                    $uploadedImages[] = '/storage/' . $path;
                }
            }
        } elseif ($request->has('photo_urls') && is_array($request->input('photo_urls'))) {
            $uploadedImages = $request->input('photo_urls');
        }

        $nextId = (ProductReport::max('id') ?? 0) + 1;
        $reportCode = '#RPTO' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

        $productId = null;
        if (!empty($validated['product_id']) && is_numeric($validated['product_id'])) {
            $pid = (int) $validated['product_id'];
            if (\App\Models\Product::where('id', $pid)->exists()) {
                $productId = $pid;
            }
        }

        $report = ProductReport::create([
            'report_code' => $reportCode,
            'product_id' => $productId,
            'user_id' => auth()->id() ?? null,
            'product_name' => $validated['product_name'] ?? 'Reported Product',
            'product_image' => !empty($uploadedImages) ? $uploadedImages[0] : '/assets/images/reported-products/hat.jpg',
            'customer_name' => $validated['full_name'],
            'customer_email' => $validated['email'],
            'customer_phone' => $validated['phone'],
            'order_number' => $validated['order_number'] ?? 'N/A',
            'purchase_date' => $validated['date_received'] ?? now()->format('M d, Y'),
            'purchase_location' => $validated['purchase_location'] ?? 'Online Store',
            'quantity' => 1,
            'issue' => $validated['issue'],
            'issue_type' => $validated['issue'],
            'category' => 'Customer Submission',
            'description' => $validated['description'],
            'status' => 'Under Review',
            'attachments_count' => count($uploadedImages),
            'product_images' => $uploadedImages,
            'admin_feedback' => null,
            'customer_replies' => [],
            'status_history' => [
                [
                    'step' => 'Reported',
                    'date' => now()->format('M d, Y h:i A'),
                    'description' => 'Reported by customer',
                    'completed' => true,
                    'current' => false,
                ],
                [
                    'step' => 'Under Review',
                    'date' => now()->format('M d, Y h:i A'),
                    'description' => 'Report is being reviewed by QA',
                    'completed' => true,
                    'current' => true,
                ],
                [
                    'step' => 'Resolution',
                    'date' => '-',
                    'description' => 'Awaiting final resolution',
                    'completed' => false,
                    'current' => false,
                ],
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product report submitted successfully',
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
            $images = [$report->product_image ?? '/assets/images/reported-products/hat.jpg'];
        }

        $resolveUrl = function ($path) {
            if (empty($path)) return '/assets/images/reported-products/hat.jpg';
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
        if (!is_array($statusHistory) || empty($statusHistory)) {
            $statusHistory = [
                ['step' => 'Reported', 'date' => optional($report->created_at)->format('M d, Y h:i A') ?? 'May 20, 2026 10:15 AM', 'description' => 'Reported by customer', 'completed' => true, 'current' => false],
                ['step' => 'Under Review', 'date' => optional($report->updated_at)->format('M d, Y h:i A') ?? 'May 21, 2026 09:30 AM', 'description' => 'Report is being reviewed', 'completed' => true, 'current' => true],
                ['step' => 'Resolution', 'date' => '-', 'description' => 'Awaiting resolution', 'completed' => false, 'current' => false]
            ];
        }

        $reportCode = $report->report_code ?? ('#RPTO' . str_pad($report->id, 5, '0', STR_PAD_LEFT));
        $customerName = $report->customer_name ?? ($report->user ? $report->user->name : 'Subrina Roberts');
        $customerEmail = $report->customer_email ?? ($report->user ? $report->user->email : 'subrina.roberts@email.com');
        $customerPhone = $report->customer_phone ?? '(678) 555-0198';

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
            'order_number' => $report->order_number ?? 'ORD123456',
            'quantity' => $report->quantity ?? 1,
            'purchase_date' => $report->purchase_date ?? optional($report->created_at)->format('M d, Y') ?? 'May 10, 2026',
            'purchase_location' => $report->purchase_location ?? 'Online Store',
            'date' => optional($report->created_at)->format('M d, Y') ?? 'May 20, 2026',
            'created_at_time' => optional($report->created_at)->format('h:i A') ?? '10:15 AM',
            'issue' => $report->issue ?? 'Product Quality',
            'issue_type' => $report->issue_type ?? $report->issue ?? 'Product Quality',
            'category' => $report->category ?? 'Stitching / Construction',
            'description' => $report->description,
            'status' => $report->status ?? 'Under Review',
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
            'customerSince' => optional($report->user ? $report->user->created_at : $report->created_at)->format('M d, Y') ?? 'Apr 12, 2024',
            'productName' => $report->product_name ?? ($report->product ? $report->product->name : 'Product'),
            'productImage' => $resolvedProductImage,
            'orderNumber' => $report->order_number ?? 'ORD123456',
            'orderDate' => $report->purchase_date ?? optional($report->created_at)->format('M d, Y') ?? 'May 10, 2026',
            'categorySub' => $report->category ?? 'Stitching / Construction',
            'categoryColor' => 'text-amber-700 dark:text-amber-400',
            'categoryBg' => 'bg-amber-100 dark:bg-amber-950/60',
            'categoryIconType' => 'stitch',
            'attachmentsCount' => $report->attachments_count ?? count($resolvedImages),
            'productImages' => $resolvedImages,
            'dateReported' => optional($report->created_at)->format('M d, Y') ?? 'May 20, 2026',
            'timeReported' => optional($report->created_at)->format('h:i A') ?? '10:15 AM',
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
