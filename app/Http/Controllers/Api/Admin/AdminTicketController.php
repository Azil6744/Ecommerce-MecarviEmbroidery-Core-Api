<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Ecommerce\Concerns\FormatsTicketPayloads;
use App\Models\EcommerceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminTicketController extends Controller
{
    use FormatsTicketPayloads;

    public function index(Request $request)
    {
        $query = EcommerceTicket::with('user:id,name,email')
            ->withCount(['replies', 'attachments']);

        if ($request->filled('status')) {
            $query->where('status', $this->normalizeKey($request->status));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $this->normalizeKey($request->priority));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && strtolower((string) $request->category) !== 'all') {
            $query->where('category', 'like', '%' . trim((string) $request->category) . '%');
        }

        match ((string) $request->query('sort', 'newest')) {
            'oldest' => $query->oldest('created_at'),
            'priority' => $query->orderByRaw("CASE LOWER(COALESCE(priority, 'normal')) WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'medium' THEN 2 ELSE 3 END")->latest('updated_at'),
            'updated' => $query->latest('updated_at'),
            default => $query->latest('created_at'),
        };

        $perPage = max(1, min(100, (int) $request->get('per_page', 15)));
        $tickets = $query->paginate($perPage);

        // Live totals across all tickets (not just this page / filter) for the stat cards.
        $counts = EcommerceTicket::query()
            ->selectRaw("LOWER(REPLACE(REPLACE(COALESCE(status, 'open'), ' ', '_'), '-', '_')) as s, COUNT(*) as c")
            ->groupBy('s')
            ->pluck('c', 's');
        $stats = [
            'total' => (int) $counts->sum(),
            'open' => (int) ($counts['open'] ?? 0),
            'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['awaiting_customer'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
        ];

        // Real trend: tickets opened in the last 7 days vs the 7 days before.
        $current = EcommerceTicket::where('created_at', '>=', now()->subDays(7))->count();
        $previous = EcommerceTicket::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();
        if ($current === $previous) {
            $stats['trend'] = ['label' => 'No change', 'direction' => 'flat', 'period' => 'vs previous 7 days'];
        } elseif ($previous === 0) {
            $stats['trend'] = ['label' => '+' . $current . ' new', 'direction' => 'up', 'period' => 'in the last 7 days'];
        } else {
            $pct = round((($current - $previous) / $previous) * 100, 1);
            $stats['trend'] = [
                'label' => ($pct > 0 ? '↑ ' : '↓ ') . abs($pct) . '%',
                'direction' => $pct > 0 ? 'up' : 'down',
                'period' => 'vs previous 7 days',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $tickets->through(fn (EcommerceTicket $ticket) => $this->ticketPayload($ticket)),
            'stats' => $stats,
        ]);
    }

    public function show(EcommerceTicket $ticket)
    {
        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->load($this->detailRelations()), true),
        ]);
    }

    public function updateStatus(Request $request, EcommerceTicket $ticket)
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,waiting_customer,resolved,closed',
            'priority' => 'nullable|in:low,normal,high',
        ]);

        $updates = ['status' => $validated['status']];

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (in_array($validated['status'], ['resolved', 'closed'], true)) {
            $updates['closed_at'] = now();
        } else {
            $updates['closed_at'] = null;
        }

        $ticket->update($updates);
        $this->recordActivity($ticket, $request->user()->id, 'status_changed', 'Status changed', 'Status changed to ' . $validated['status']);

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    public function addReply(Request $request, EcommerceTicket $ticket)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'admin_reply' => true,
            'message' => $validated['message'],
            'attachments' => $request->attachments,
        ]);

        $ticket->update([
            'status' => 'waiting_customer',
            'last_staff_reply_at' => now(),
        ]);

        $this->recordActivity($ticket, $request->user()->id, 'staff_reply', 'Support replied', Str::limit($validated['message'], 140));

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    public function addNote(Request $request, EcommerceTicket $ticket)
    {
        $validated = $request->validate([
            'note' => 'required|string',
            'visibility' => 'nullable|in:public,internal',
        ]);

        $note = $ticket->notes()->create([
            'user_id' => $request->user()->id,
            'note' => $validated['note'],
            'visibility' => $validated['visibility'] ?? 'internal',
        ]);

        $this->recordActivity($ticket, $request->user()->id, 'note_added', 'Note added', Str::limit($validated['note'], 140));

        return response()->json([
            'success' => true,
            'data' => $this->notePayload($note->load('user:id,name,email')),
        ], 201);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'nullable|string',
            'description' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'status' => 'nullable|in:open,in_progress,waiting_customer,resolved,closed',
        ]);

        $ticketNumber = '#ST-' . date('Ymd') . '-' . str_pad((string) mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $message = $validated['message'] ?? $validated['description'] ?? 'Admin logged ticket';

        $ticket = EcommerceTicket::create([
            'ticket_number' => $ticketNumber,
            'user_id' => $request->user()->id,
            'customer_name' => $validated['customer_name'] ?? 'Customer',
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'subject' => $validated['subject'],
            'category' => $validated['department'] ?? $validated['category'] ?? 'General',
            'priority' => $validated['priority'] ?? 'normal',
            'status' => $validated['status'] ?? 'open',
            'message' => $message,
            'source_page' => 'admin_panel',
        ]);

        $this->recordActivity($ticket, $request->user()->id, 'created', 'Ticket Created', "Ticket logged by {$request->user()->name}");

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ], 201);
    }

    public function update(Request $request, EcommerceTicket $ticket)
    {
        $validated = $request->validate([
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string',
            'description' => 'nullable|string',
            'department' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'status' => 'nullable|in:open,in_progress,waiting_customer,resolved,closed',
        ]);

        $updates = [];
        if (isset($validated['subject'])) $updates['subject'] = $validated['subject'];
        if (isset($validated['department'])) $updates['category'] = $validated['department'];
        if (isset($validated['category'])) $updates['category'] = $validated['category'];
        if (isset($validated['priority'])) $updates['priority'] = $validated['priority'];
        if (isset($validated['status'])) {
            $updates['status'] = $validated['status'];
            if (in_array($validated['status'], ['resolved', 'closed'], true)) {
                $updates['closed_at'] = now();
            } else {
                $updates['closed_at'] = null;
            }
        }
        if (isset($validated['description'])) $updates['message'] = $validated['description'];
        if (isset($validated['message'])) $updates['message'] = $validated['message'];

        $ticket->update($updates);
        $this->recordActivity($ticket, $request->user()->id, 'status_changed', 'Ticket Updated', "Ticket updated by {$request->user()->name}");

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    public function close(Request $request, EcommerceTicket $ticket)
    {
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        $this->recordActivity($ticket, $request->user()->id, 'closed', 'Ticket closed', 'Support closed the ticket.');

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    protected function includeInternalTicketNotes(): bool
    {
        return true;
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function recordActivity(EcommerceTicket $ticket, ?int $userId, string $type, string $title, ?string $description = null): void
    {
        $ticket->activities()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
        ]);
    }
}
