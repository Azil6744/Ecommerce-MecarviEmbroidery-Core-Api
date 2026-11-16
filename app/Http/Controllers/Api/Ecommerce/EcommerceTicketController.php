<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Ecommerce\Concerns\FormatsTicketPayloads;
use App\Models\EcommerceTicket;
use App\Models\EcommerceTicketAttachment;
use App\Models\EcommerceTicketNote;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class EcommerceTicketController extends Controller
{
    use FormatsTicketPayloads;

    private const CUSTOMER_STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high'];

    public function index(Request $request)
    {
        $query = EcommerceTicket::query()
            ->with(['user:id,name,email', 'replies:id,ecommerce_ticket_id'])
            ->withCount(['replies', 'attachments'])
            ->where('user_id', $request->user()->id);

        if ($status = $this->normalizeStatus($request->query('status'))) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($priority = $this->normalizePriority($request->query('priority'))) {
            $query->where('priority', $priority);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $sort = (string) $request->query('sort', 'latest');
        match ($sort) {
            'oldest' => $query->oldest(),
            'updated' => $query->latest('updated_at'),
            default => $query->latest(),
        };

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $tickets = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $tickets->through(fn (EcommerceTicket $ticket) => $this->ticketPayload($ticket)),
            'summary' => $this->summaryForUser($request),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'preferred_contact_method' => ['nullable', 'in:email,phone'],
            'is_urgent' => ['sometimes', 'boolean'],
            'isUrgent' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $user = $request->user();
        $priority = $this->normalizePriority($validated['priority'] ?? null) ?: 'normal';
        $isUrgent = (bool) ($validated['isUrgent'] ?? $validated['is_urgent'] ?? false);
        if ($isUrgent) {
            $priority = 'high';
        }
        // "Priority Support" in the customer's membership plan: their tickets start as high priority.
        if (in_array($priority, ['low', 'normal', 'medium'], true)
            && \App\Services\MembershipBenefitService::perksFor($user)['priority_support']) {
            $priority = 'high';
        }

        $ticket = EcommerceTicket::create([
            'ticket_number' => $this->nextTicketNumber(),
            'user_id' => $user->id,
            'customer_name' => $validated['customer_name'] ?? $user->name,
            'contact_email' => $validated['contact_email'] ?? $user->email,
            'contact_phone' => $validated['contact_phone'] ?? $user->phone ?? null,
            'preferred_contact_method' => $validated['preferred_contact_method'] ?? 'email',
            'subject' => $validated['subject'],
            'category' => $validated['category'] ?? null,
            'priority' => $priority,
            'is_urgent' => $isUrgent,
            'status' => 'open',
            'message' => $validated['message'],
            'source_page' => 'user_panel',
            'last_customer_reply_at' => now(),
        ]);

        $this->recordActivity($ticket, $user->id, 'created', 'Ticket created', $ticket->subject);
        $this->storeUploadedAttachments($request, $ticket);

        return response()->json([
            'success' => true,
            'message' => 'Ticket created successfully.',
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations())),
        ], 201);
    }

    public function publicStore(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'source_page' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $ticket = EcommerceTicket::create([
            'ticket_number' => $this->nextTicketNumber(),
            'user_id' => optional($request->user())->id,
            'product_id' => $validated['product_id'] ?? null,
            'customer_name' => $validated['customer_name'] ?? 'Product detail visitor',
            'contact_email' => $validated['customer_email'] ?? null,
            'subject' => $validated['subject'] ?? 'Product support request',
            'message' => $validated['message'] ?? 'Customer requested help from the product detail page.',
            'source_page' => $validated['source_page'] ?? 'product_detail',
            'category' => $validated['category'] ?? 'product_report',
            'status' => 'open',
            'priority' => 'normal',
            'metadata' => array_merge($validated['metadata'] ?? [], [
                'customer_email' => $validated['customer_email'] ?? null,
            ]),
        ]);

        $this->recordActivity($ticket, optional($request->user())->id, 'created', 'Ticket created', $ticket->subject);

        return response()->json(['success' => true, 'data' => $this->ticketPayload($ticket)], 201);
    }

    public function show(Request $request, EcommerceTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->load($this->detailRelations()), true),
        ]);
    }

    public function update(Request $request, EcommerceTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);

        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string'],
            'assigned_to' => ['sometimes', 'nullable', 'string'],
        ]);

        $updates = [];

        if (array_key_exists('status', $validated)) {
            $status = $this->normalizeStatus($validated['status']);
            abort_if(! $status, 422, 'Invalid status.');
            $updates['status'] = $status;
            $updates['closed_at'] = in_array($status, ['closed', 'resolved'], true) ? now() : null;
        }

        if (array_key_exists('priority', $validated)) {
            $priority = $this->normalizePriority($validated['priority']);
            abort_if(! $priority, 422, 'Invalid priority.');
            $updates['priority'] = $priority;
        }

        if (array_key_exists('assigned_to', $validated)) {
            $metadata = $ticket->metadata ?: [];
            $metadata['assigned_to'] = $validated['assigned_to'];
            $updates['metadata'] = $metadata;
        }

        if ($updates) {
            $ticket->update($updates);
            $this->recordActivity($ticket, $request->user()->id, 'updated', 'Ticket updated', 'Customer updated the ticket.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    public function destroy(Request $request, EcommerceTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        $ticket->delete();

        return response()->json(['success' => true, 'message' => 'Ticket deleted successfully.']);
    }

    public function addReply(Request $request, EcommerceTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);

        $validated = $request->validate([
            'message' => ['required', 'string'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $user = $request->user();
        $reply = $ticket->replies()->create([
            'user_id' => $user->id,
            'admin_reply' => false,
            'message' => $validated['message'],
            'attachments' => null,
        ]);

        $ticket->update([
            'status' => 'open',
            'last_customer_reply_at' => now(),
        ]);

        $this->storeUploadedAttachments($request, $ticket, $reply->id);
        $this->recordActivity($ticket, $user->id, 'customer_reply', 'Customer replied', Str::limit($validated['message'], 140));

        return response()->json([
            'success' => true,
            'message' => 'Reply added successfully.',
            'data' => $this->ticketPayload($ticket->fresh()->load($this->detailRelations()), true),
        ]);
    }

    public function uploadAttachment(Request $request, EcommerceTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);

        $request->validate([
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $attachments = $this->storeUploadedAttachments($request, $ticket);
        $this->recordActivity($ticket, $request->user()->id, 'attachment_added', 'Attachment uploaded', count($attachments) . ' file(s) uploaded.');

        return response()->json([
            'success' => true,
            'data' => $attachments->map(fn (EcommerceTicketAttachment $attachment) => $this->attachmentPayload($attachment)),
        ], 201);
    }

    private function storeUploadedAttachments(Request $request, EcommerceTicket $ticket, ?int $replyId = null)
    {
        $files = $request->file('attachments', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        return collect($files)->map(function (UploadedFile $file) use ($request, $ticket, $replyId) {
            $path = $file->store('ticket-attachments/' . $ticket->id, 'public');

            return $ticket->attachments()->create([
                'ecommerce_ticket_reply_id' => $replyId,
                'user_id' => $request->user()?->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
            ]);
        });
    }

    private function recordActivity(EcommerceTicket $ticket, ?int $userId, string $type, string $title, ?string $description = null, array $metadata = []): void
    {
        $ticket->activities()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function summaryForUser(Request $request): array
    {
        $counts = EcommerceTicket::where('user_id', $request->user()->id)
            ->selectRaw('LOWER(status) as status_key, COUNT(*) as total')
            ->groupBy('status_key')
            ->pluck('total', 'status_key');

        $open = (int) ($counts['open'] ?? 0);
        $inProgress = (int) ($counts['in_progress'] ?? 0);
        $waitingCustomer = (int) ($counts['waiting_customer'] ?? 0);
        $resolved = (int) ($counts['resolved'] ?? 0);
        $closed = (int) ($counts['closed'] ?? 0);

        return [
            'open' => $open,
            'in_progress' => $inProgress,
            'waiting_customer' => $waitingCustomer,
            'resolved' => $resolved,
            'closed' => $closed,
            'total' => $open + $inProgress + $waitingCustomer + $resolved + $closed,
        ];
    }

    private function authorizeTicket(Request $request, EcommerceTicket $ticket): void
    {
        abort_if($ticket->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin(), 403, 'Unauthorized');
    }

    private function nextTicketNumber(): string
    {
        do {
            $number = 'SUP-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (EcommerceTicket::where('ticket_number', $number)->exists());

        return $number;
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $status));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return $normalized === 'all' || in_array($normalized, self::CUSTOMER_STATUSES, true)
            ? $normalized
            : null;
    }

    private function normalizePriority(mixed $priority): ?string
    {
        if ($priority === null || $priority === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $priority));

        return in_array($normalized, self::PRIORITIES, true) ? $normalized : null;
    }

    private function displayStatus(?string $status): string
    {
        return str((string) ($this->normalizeStatus($status) ?: 'open'))->replace('_', ' ')->title()->toString();
    }

    private function displayPriority(?string $priority): string
    {
        return str((string) ($this->normalizePriority($priority) ?: 'normal'))->title()->toString();
    }
}
