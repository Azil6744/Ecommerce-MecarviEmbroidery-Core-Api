<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceDispute;
use App\Models\EcommerceDisputeType;
use App\Models\EcommerceOrder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EcommerceDisputeController extends Controller
{
    private const ALLOWED_STATUSES = ['Open', 'Under Review', 'Awaiting Response', 'Resolved', 'Closed'];

    public function index(Request $request)
    {
        $user = $request->user();

        $query = EcommerceDispute::query()->with(['order.items', 'disputeType.questions'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('order_number')) {
            $query->where('order_number', 'like', '%' . $request->string('order_number') . '%');
        }

        if ($request->filled('dispute_type_id')) {
            $query->where('dispute_type_id', $request->input('dispute_type_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('dispute_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('dispute_type_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $isAdmin = $request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()));

        if (!$isAdmin) {
            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                // Public lookup only; never expose full disputes list to guests.
                if (!$request->filled('order_number') && !$request->filled('search')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Guest lookup requires order_number or search.',
                    ], 422);
                }

                $query->whereNotNull('order_number');
            }
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $data = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'dispute_type_id' => ['nullable', 'integer', 'exists:ecommerce_dispute_types,id'],
            'dispute_type_name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'issue_type' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'expected_resolution' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable'],
            'answers' => ['nullable'],
            'status' => ['nullable', Rule::in(self::ALLOWED_STATUSES)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov', 'max:20480'],
        ]);

        $user = $request->user();

        // Resolve dispute type name
        $disputeTypeName = $validated['dispute_type_name'] ?? null;
        if (!empty($validated['dispute_type_id'])) {
            $typeModel = EcommerceDisputeType::find($validated['dispute_type_id']);
            if ($typeModel) {
                $disputeTypeName = $typeModel->name;
            }
        }

        $typeLabel = $disputeTypeName
            ?? $validated['issue_type']
            ?? $validated['type']
            ?? $validated['reason']
            ?? 'General Dispute';

        // Parse items
        $items = $validated['items'] ?? null;
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        // Parse answers
        $answers = $validated['answers'] ?? null;
        if (is_string($answers)) {
            $answers = json_decode($answers, true) ?: [];
        }
        if (!is_array($answers)) {
            $answers = [];
        }

        // Handle question file uploads
        foreach ($request->allFiles() as $fileKey => $uploadedFile) {
            if (str_starts_with($fileKey, 'question_file_') || str_starts_with($fileKey, 'qfile_')) {
                $fieldKey = preg_replace('/^(question_file_|qfile_)/', '', $fileKey);
                if (is_array($uploadedFile)) {
                    $paths = [];
                    foreach ($uploadedFile as $f) {
                        $paths[] = Storage::url($f->store('disputes/evidence', 'public'));
                    }
                    $answers[$fieldKey] = $paths;
                } else {
                    $answers[$fieldKey] = Storage::url($uploadedFile->store('disputes/evidence', 'public'));
                }
            }
        }

        // General evidence files
        $evidenceFiles = [];
        if ($request->hasFile('evidence')) {
            foreach ($request->file('evidence') as $file) {
                $evidenceFiles[] = Storage::url($file->store('disputes/evidence', 'public'));
            }
        }

        $description = $validated['description'] ?? '';
        if (empty($description) && !empty($answers)) {
            // Build a human-readable description summary from answers
            $lines = [];
            foreach ($answers as $k => $v) {
                if (is_array($v)) $v = implode(', ', $v);
                $lines[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
            }
            $description = implode("\n", $lines);
        }
        if (empty($description)) {
            $description = "Dispute filed for order " . $validated['order_number'];
        }

        $payload = [
            'dispute_number' => $this->generateDisputeNumber(),
            'order_number' => $validated['order_number'],
            'customer_name' => $validated['customer_name'] ?? $validated['name'] ?? ($user?->name),
            'dispute_type_id' => $validated['dispute_type_id'] ?? null,
            'dispute_type_name' => $disputeTypeName,
            'type' => $typeLabel,
            'status' => $validated['status'] ?? 'Open',
            'description' => $description,
            'expected_resolution' => $validated['expected_resolution'] ?? null,
            'items' => $items,
            'answers' => $answers,
            'amount' => $validated['amount'] ?? 0,
            'evidence' => $evidenceFiles,
        ];

        if ($user && Schema::hasColumn((new EcommerceDispute)->getTable(), 'user_id')) {
            $payload['user_id'] = $user->id;
        }

        $order = EcommerceOrder::where('order_number', $validated['order_number'])->first();
        if ($order && !$payload['customer_name']) {
            $payload['customer_name'] = $order->customer_name ?? $user?->name;
        }

        if (Schema::hasColumn((new EcommerceDispute)->getTable(), 'email') && isset($validated['email'])) {
            $payload['email'] = $validated['email'];
        }

        if (Schema::hasColumn((new EcommerceDispute)->getTable(), 'phone') && isset($validated['phone'])) {
            $payload['phone'] = $validated['phone'];
        }

        $item = EcommerceDispute::create($payload);

        // Notify customer of new dispute submission & record order status event
        try {
            if (class_exists(\App\Services\EmailNotificationService::class)) {
                app(\App\Services\EmailNotificationService::class)->sendDisputeEvent('dispute_opened', $item);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify customer of dispute submission: ' . $e->getMessage());
        }

        if ($item->order && method_exists($item->order, 'recordStatusEvent')) {
            $item->order->recordStatusEvent(
                'dispute_opened',
                'Dispute opened: ' . $item->dispute_number . ' (' . $item->type . ')',
                $item->description
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Dispute submitted successfully.',
            'data' => [
                'dispute' => $item->load(['disputeType.questions', 'order.items']),
                'reference' => $item->dispute_number,
            ],
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $item = $this->resolveDispute($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Dispute not found'], 404);
        }

        if (!$this->canAccess($request, $item)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $item->load(['disputeType.questions', 'order.items']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $item = $this->resolveDispute($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Dispute not found'], 404);
        }

        $user = $request->user();
        if (!$this->canAccess($request, $item)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $rules = [
            'description' => ['sometimes', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'max:255'],
            'issue_type' => ['sometimes', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(self::ALLOWED_STATUSES)],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_resolution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
        $validated = $request->validate($rules);

        $isAdmin = $request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()));
        if (!$isAdmin && array_key_exists('status', $validated)) {
            return response()->json(['success' => false, 'message' => 'Only admins can change dispute status'], 403);
        }

        if (isset($validated['issue_type']) && !isset($validated['type'])) {
            $validated['type'] = $validated['issue_type'];
            unset($validated['issue_type']);
        }

        if (Schema::hasColumn((new EcommerceDispute)->getTable(), 'evidence') && $request->hasFile('evidence')) {
            $files = is_array($item->evidence) ? $item->evidence : [];
            foreach ($request->file('evidence') as $file) {
                $files[] = Storage::url($file->store('disputes/evidence', 'public'));
            }
            $validated['evidence'] = $files;
        }

        $previousStatus = $item->status;
        $item->update($validated);

        if (isset($validated['status']) && $previousStatus !== $validated['status']) {
            $disputeEventKey = match ($validated['status']) {
                'Under Review' => 'dispute_under_review',
                'Awaiting Response' => 'dispute_awaiting_response',
                'Resolved' => 'dispute_resolved',
                'Closed' => 'dispute_closed',
                default => null,
            };

            if ($disputeEventKey) {
                try {
                    if (class_exists(\App\Services\EmailNotificationService::class)) {
                        app(\App\Services\EmailNotificationService::class)->sendDisputeEvent($disputeEventKey, $item, [
                            'notes' => $validated['description'] ?? 'Case status updated.',
                            'resolution_notes' => $validated['description'] ?? 'The dispute has been resolved.',
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to notify dispute status update [{$disputeEventKey}]: " . $e->getMessage());
                }

                if ($item->order && method_exists($item->order, 'recordStatusEvent')) {
                    $item->order->recordStatusEvent(
                        'dispute_' . strtolower(str_replace(' ', '_', $validated['status'])),
                        'Dispute #' . $item->dispute_number . ' status updated to ' . $validated['status'],
                        $validated['description'] ?? null
                    );
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Dispute updated successfully.',
            'data' => $item->fresh(['order.items', 'disputeType.questions']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $item = $this->resolveDispute($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Dispute not found'], 404);
        }

        if (!$this->canAccess($request, $item)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $item->delete();

        return response()->json(['success' => true, 'message' => 'Dispute deleted successfully']);
    }

    private function resolveDispute($id): ?EcommerceDispute
    {
        return EcommerceDispute::where('id', $id)
            ->orWhere('dispute_number', $id)
            ->first();
    }

    private function canAccess(Request $request, EcommerceDispute $item): bool
    {
        $user = $request->user();

        if ($request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()))) {
            return true;
        }

        if ($user && $item->user_id) {
            return (int) $user->id === (int) $item->user_id;
        }

        if ($request->filled('email') && $item->email) {
            return strtolower((string) $request->input('email')) === strtolower((string) $item->email);
        }

        if ($request->filled('order_number')) {
            return (string) $request->input('order_number') === (string) $item->order_number;
        }

        return false;
    }

    private function generateDisputeNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        return "DSP-{$date}-{$random}";
    }
}
