<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Ecommerce\Concerns\FormatsConversationPayloads;
use App\Models\EcommerceConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EcommerceConversationController extends Controller
{
    use FormatsConversationPayloads;

    public function index(Request $request)
    {
        $user = $request->user();
        $query = EcommerceConversation::query()
            ->where('user_id', $user->id)
            ->with([
                'user:id,name,email',
                'latestMessage.sender:id,name,email',
                'messages.sender:id,name,email'
            ])
            ->withCount('messages');

        if ($request->filled('linked_type')) {
            $query->where('linked_type', $request->string('linked_type'));
        }

        if ($request->filled('linked_id')) {
            $query->where('linked_id', $request->integer('linked_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->lower());
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($nested) use ($search) {
                $nested->where('subject', 'like', $search)
                    ->orWhere('linked_label', 'like', $search)
                    ->orWhereHas('messages', fn ($messages) => $messages->where('message', 'like', $search));
            });
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $conversations->getCollection()->map(fn ($conversation) => $this->conversationPayload($conversation, true))->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'linked_type' => ['nullable', 'string', 'max:80'],
            'linked_id' => ['nullable', 'integer'],
            'linked_label' => ['nullable', 'string', 'max:255'],
        ]);

        $now = Carbon::now();
        $userId = $request->user()->id;

        // Check if user already has an existing conversation for this entity/link
        $conversation = null;
        if (isset($validated['linked_type']) && isset($validated['linked_id'])) {
            $conversation = EcommerceConversation::where('user_id', $userId)
                ->where('linked_type', $validated['linked_type'])
                ->where('linked_id', $validated['linked_id'])
                ->first();
        }

        if ($conversation) {
            // Update existing conversation
            $conversation->update([
                'status' => 'open',
                'subject' => $validated['subject'] ?? $conversation->subject ?? 'General Support',
                'linked_type' => $validated['linked_type'] ?? $conversation->linked_type,
                'linked_id' => $validated['linked_id'] ?? $conversation->linked_id,
                'linked_label' => $validated['linked_label'] ?? $conversation->linked_label,
                'last_customer_message_at' => $now,
                'last_message_at' => $now,
                'closed_at' => null,
            ]);
        } else {
            // Create a new one
            $conversation = EcommerceConversation::create([
                'user_id' => $userId,
                'subject' => $validated['subject'] ?? 'General Support',
                'status' => 'open',
                'linked_type' => $validated['linked_type'] ?? null,
                'linked_id' => $validated['linked_id'] ?? null,
                'linked_label' => $validated['linked_label'] ?? null,
                'last_customer_message_at' => $now,
                'last_message_at' => $now,
            ]);
        }

        $conversation->messages()->create([
            'sender_id' => $userId,
            'sender_type' => 'customer',
            'message' => $validated['message'],
        ]);

        try {
            $user = $request->user();
            app(\App\Services\EmailNotificationService::class)->sendEvent('message_from_customer', [
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'message_preview' => \Illuminate\Support\Str::limit($validated['message'], 150),
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
            ], $user->email);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed sending message_from_customer email: ' . $e->getMessage());
        }

        $conversation->load($this->conversationRelations());

        return response()->json(['data' => $this->conversationPayload($conversation)], 201);
    }

    public function show(Request $request, EcommerceConversation $conversation)
    {
        $this->authorizeCustomer($request, $conversation);
        $conversation->load($this->conversationRelations());

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function addMessage(Request $request, EcommerceConversation $conversation)
    {
        $this->authorizeCustomer($request, $conversation);
        $hasFile = $request->hasFile('file') || $request->hasFile('attachment');

        $validated = $request->validate([
            'message' => [$hasFile ? 'nullable' : 'required', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'max:25600'],
            'attachment' => ['nullable'],
        ]);

        $uploadedFile = $request->file('file') ?: $request->file('attachment');
        $messageText = $validated['message'] ?? '';

        if ($uploadedFile) {
            $path = $uploadedFile->store('conversation-attachments/' . $conversation->id, 'public');
            $url = asset('storage/' . $path);
            $mime = $uploadedFile->getClientMimeType() ?: 'application/octet-stream';
            $isImage = str_starts_with($mime, 'image/');
            $isPdf = str_contains($mime, 'pdf');
            $fileType = $isImage ? 'image' : ($isPdf ? 'pdf' : 'document');
            $sizeBytes = $uploadedFile->getSize() ?: 0;
            $formattedSize = $sizeBytes > 1048576 
                ? number_format($sizeBytes / 1048576, 1) . ' MB' 
                : round($sizeBytes / 1024) . ' KB';

            $attachmentTag = '[attachment:' . json_encode([
                'name' => $uploadedFile->getClientOriginalName(),
                'size' => $formattedSize,
                'type' => $fileType,
                'url' => $url,
            ]) . ']';

            $messageText = trim($messageText) !== '' 
                ? trim($messageText) . "\n\n" . $attachmentTag 
                : $attachmentTag;
        }

        $now = Carbon::now();
        $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_type' => 'customer',
            'message' => $messageText,
        ]);

        $conversation->update([
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
            'last_customer_message_at' => $now,
            'last_message_at' => $now,
            'closed_at' => null,
        ]);

        try {
            $user = $request->user();
            app(\App\Services\EmailNotificationService::class)->sendEvent('message_from_customer', [
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'message_preview' => \Illuminate\Support\Str::limit($validated['message'], 150),
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
            ], $user->email);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed sending message_from_customer email: ' . $e->getMessage());
        }

        $conversation->load($this->conversationRelations());

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function close(Request $request, EcommerceConversation $conversation)
    {
        $this->authorizeCustomer($request, $conversation);
        $conversation->update([
            'status' => 'closed',
            'closed_at' => Carbon::now(),
        ]);

        $conversation->load($this->conversationRelations());

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    private function authorizeCustomer(Request $request, EcommerceConversation $conversation): void
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->id, 403, 'You cannot access this conversation.');
    }
}
