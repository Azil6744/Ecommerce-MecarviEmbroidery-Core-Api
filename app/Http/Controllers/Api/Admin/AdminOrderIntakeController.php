<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderIntake;
use Illuminate\Http\Request;

class AdminOrderIntakeController extends Controller
{
    public function index(Request $request)
    {
        $allIntakes = OrderIntake::query()->orderByDesc('id')->get();

        $stats = [
            'total' => $allIntakes->count(),
            'completed' => $allIntakes->filter(fn ($o) => strtolower((string) $o->status) === 'completed')->count(),
            'inProgress' => $allIntakes->filter(fn ($o) => strtolower((string) $o->status) === 'in progress')->count(),
            'ready' => $allIntakes->filter(fn ($o) => strtolower((string) $o->status) === 'ready for pickup')->count(),
            'overdue' => $allIntakes->filter(fn ($o) => in_array(strtolower((string) $o->status), ['overdue', 'received'], true))->count(),
        ];

        $filtered = $allIntakes;

        if ($request->filled('status') && strtolower($request->string('status')) !== 'all') {
            $statusParam = strtolower((string) $request->string('status'));
            $filtered = $filtered->filter(function ($o) use ($statusParam) {
                $s = strtolower((string) $o->status);
                if ($statusParam === 'overdue') {
                    return in_array($s, ['overdue', 'received'], true);
                }
                return $s === $statusParam;
            });
        }

        if ($request->filled('search')) {
            $q = strtolower(trim((string) $request->string('search')));
            $filtered = $filtered->filter(function ($o) use ($q) {
                $cust = (array) ($o->customer ?? []);
                return str_contains(strtolower((string) $o->order_number), $q)
                    || str_contains(strtolower((string) ($cust['name'] ?? '')), $q)
                    || str_contains(strtolower((string) ($cust['customerNumber'] ?? '')), $q)
                    || str_contains(strtolower((string) ($cust['email'] ?? '')), $q)
                    || str_contains(strtolower((string) ($cust['businessName'] ?? '')), $q);
            });
        }

        $sort = strtolower((string) $request->input('sort', 'newest'));
        if ($sort === 'oldest') {
            $filtered = $filtered->sortBy('id');
        } elseif ($sort === 'items') {
            $filtered = $filtered->sortByDesc(function ($o) {
                return collect($o->items ?? [])->sum(fn ($it) => (int) ($it['receivedQty'] ?? 0));
            });
        }

        $data = $filtered->values()->map(fn (OrderIntake $o) => $this->transform($o));

        return response()->json([
            'success' => true,
            'data' => $data,
            'stats' => $stats,
        ]);
    }

    public function show(string $id)
    {
        $intake = $this->findIntake($id);
        if (! $intake) {
            return response()->json(['success' => false, 'message' => 'Order intake not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transform($intake),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_number' => ['nullable', 'string', 'max:100'],
            'orderNumber' => ['nullable', 'string', 'max:100'],
            'drop_off_date' => ['nullable', 'string', 'max:64'],
            'dropOffDate' => ['nullable', 'string', 'max:64'],
            'pick_up_date' => ['nullable', 'string', 'max:64'],
            'pickUpDate' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:50'],
            'accent_color' => ['nullable', 'string', 'max:32'],
            'accentColor' => ['nullable', 'string', 'max:32'],
            'assigned_staff' => ['nullable', 'string', 'max:120'],
            'assignedStaff' => ['nullable', 'string', 'max:120'],
            'priority_status' => ['nullable', 'string', 'max:50'],
            'priorityStatus' => ['nullable', 'string', 'max:50'],
            'customer' => ['required', 'array'],
            'items' => ['nullable', 'array'],
            'drop_off' => ['nullable', 'array'],
            'dropOff' => ['nullable', 'array'],
            'pick_up' => ['nullable', 'array'],
            'pickUp' => ['nullable', 'array'],
            'internal_notes' => ['nullable', 'string'],
            'internalNotes' => ['nullable', 'string'],
        ]);

        $orderNumber = $validated['order_number'] ?? $validated['orderNumber'] ?? null;
        if (! $orderNumber) {
            $orderNumber = 'OI-' . now()->format('Ymd') . sprintf('%03d', rand(100, 999));
        }

        $status = $validated['status'] ?? 'In Progress';
        $accentColor = $validated['accent_color'] ?? $validated['accentColor'] ?? $this->accentForStatus($status);

        $intake = OrderIntake::create([
            'order_number' => $orderNumber,
            'drop_off_date' => $validated['drop_off_date'] ?? $validated['dropOffDate'] ?? now()->format('M d, Y'),
            'pick_up_date' => $validated['pick_up_date'] ?? $validated['pickUpDate'] ?? '—',
            'status' => $status,
            'accent_color' => $accentColor,
            'assigned_staff' => $validated['assigned_staff'] ?? $validated['assignedStaff'] ?? null,
            'priority_status' => $validated['priority_status'] ?? $validated['priorityStatus'] ?? null,
            'customer' => $validated['customer'],
            'items' => $validated['items'] ?? [],
            'drop_off' => $validated['drop_off'] ?? $validated['dropOff'] ?? null,
            'pick_up' => $validated['pick_up'] ?? $validated['pickUp'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? $validated['internalNotes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->transform($intake),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $intake = $this->findIntake($id);
        if (! $intake) {
            return response()->json(['success' => false, 'message' => 'Order intake not found'], 404);
        }

        $status = $request->input('status', $intake->status);
        $accentColor = $request->input('accent_color')
            ?? $request->input('accentColor')
            ?? ($request->has('status') ? $this->accentForStatus($status) : $intake->accent_color);

        $intake->update([
            'order_number' => $request->input('order_number', $request->input('orderNumber', $intake->order_number)),
            'drop_off_date' => $request->input('drop_off_date', $request->input('dropOffDate', $intake->drop_off_date)),
            'pick_up_date' => $request->input('pick_up_date', $request->input('pickUpDate', $intake->pick_up_date)),
            'status' => $status,
            'accent_color' => $accentColor,
            'assigned_staff' => $request->input('assigned_staff', $request->input('assignedStaff', $intake->assigned_staff)),
            'priority_status' => $request->input('priority_status', $request->input('priorityStatus', $intake->priority_status)),
            'customer' => $request->input('customer', $intake->customer),
            'items' => $request->input('items', $intake->items),
            'drop_off' => $request->input('drop_off', $request->input('dropOff', $intake->drop_off)),
            'pick_up' => $request->input('pick_up', $request->input('pickUp', $intake->pick_up)),
            'internal_notes' => $request->input('internal_notes', $request->input('internalNotes', $intake->internal_notes)),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->transform($intake->fresh()),
        ]);
    }

    public function destroy(string $id)
    {
        $intake = $this->findIntake($id);
        if (! $intake) {
            return response()->json(['success' => false, 'message' => 'Order intake not found'], 404);
        }

        $intake->delete();

        return response()->json(['success' => true]);
    }

    private function findIntake(string $id): ?OrderIntake
    {
        if (is_numeric($id)) {
            $found = OrderIntake::find((int) $id);
            if ($found) {
                return $found;
            }
        }

        return OrderIntake::where('order_number', $id)->first();
    }

    private function accentForStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => '#10B981',
            'ready for pickup' => '#8B5CF6',
            'overdue' => '#E11D48',
            'received' => '#0284C7',
            default => '#F97316',
        };
    }

    private function transform(OrderIntake $intake): array
    {
        return [
            'id' => (string) $intake->id,
            'orderNumber' => $intake->order_number,
            'createdAt' => $intake->created_at
                ? $intake->created_at->format('M d, Y h:i A')
                : now()->format('M d, Y h:i A'),
            'dropOffDate' => $intake->drop_off_date ?: '—',
            'pickUpDate' => $intake->pick_up_date ?: '—',
            'status' => $intake->status ?: 'In Progress',
            'accentColor' => $intake->accent_color ?: $this->accentForStatus((string) $intake->status),
            'assignedStaff' => $intake->assigned_staff ?? '',
            'priorityStatus' => $intake->priority_status ?? '',
            'customer' => $intake->customer ?? [
                'name' => 'Customer',
                'customerNumber' => '',
                'businessName' => '',
                'phone' => '',
                'email' => '',
                'initials' => 'CU',
            ],
            'items' => $intake->items ?? [],
            'dropOff' => $intake->drop_off ?? [
                'byCustomer' => '',
                'date' => $intake->drop_off_date ?: '—',
                'byStaff' => '',
                'totalItems' => 0,
            ],
            'pickUp' => $intake->pick_up ?? [
                'byCustomer' => '',
                'date' => $intake->pick_up_date ?: '—',
                'byStaff' => '',
                'totalItems' => 0,
            ],
            'internalNotes' => $intake->internal_notes ?? '',
        ];
    }
}
