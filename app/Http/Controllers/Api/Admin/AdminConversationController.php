<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Ecommerce\Concerns\FormatsConversationPayloads;
use App\Models\EcommerceConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminConversationController extends Controller
{
    use FormatsConversationPayloads;

    public function index(Request $request)
    {
        $query = EcommerceConversation::query()
            ->with(['user:id,name,email', 'latestMessage.sender:id,name,email'])
            ->withCount([
                'messages',
                'messages as unread_customer_messages_count' => fn ($messages) => $messages
                    ->where('sender_type', 'customer')
                    ->whereNull('read_at'),
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->lower());
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($nested) use ($search) {
                $nested->where('subject', 'like', $search)
                    ->orWhere('linked_label', 'like', $search)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $search)->orWhere('email', 'like', $search))
                    ->orWhereHas('messages', fn ($messages) => $messages->where('message', 'like', $search));
            });
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => $conversations->getCollection()->map(fn ($conversation) => $this->conversationPayload($conversation, false))->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function show(EcommerceConversation $conversation)
    {
        $conversation->messages()
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $conversation->load($this->conversationRelations());
        $conversation->loadCount([
            'messages',
            'messages as unread_customer_messages_count' => fn ($messages) => $messages
                ->where('sender_type', 'customer')
                ->whereNull('read_at'),
        ]);

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function addMessage(Request $request, EcommerceConversation $conversation)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $now = Carbon::now();
        $conversation->messages()->create([
            'sender_id' => $request->user()?->id,
            'sender_type' => 'admin',
            'message' => $validated['message'],
        ]);

        $conversation->update([
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
            'last_admin_message_at' => $now,
            'last_message_at' => $now,
            'closed_at' => null,
        ]);

        $conversation->load($this->conversationRelations());
        $conversation->loadCount([
            'messages',
            'messages as unread_customer_messages_count' => fn ($messages) => $messages
                ->where('sender_type', 'customer')
                ->whereNull('read_at'),
        ]);

        try {
            $customer = $conversation->user;
            if ($customer && $customer->email) {
                app(\App\Services\EmailNotificationService::class)->sendEvent('message_sent', [
                    'recipient_name' => $customer->name,
                    'sender_name' => optional($request->user())->name ?: 'Mecarvi Support',
                    'message_preview' => \Illuminate\Support\Str::limit($validated['message'], 150),
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $customer->email);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed sending message_sent email: ' . $e->getMessage());
        }

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function updateStatus(Request $request, EcommerceConversation $conversation)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:open,pending,closed'],
        ]);

        $conversation->update([
            'status' => $validated['status'],
            'closed_at' => $validated['status'] === 'closed' ? Carbon::now() : null,
        ]);

        $conversation->load($this->conversationRelations());
        $conversation->loadCount([
            'messages',
            'messages as unread_customer_messages_count' => fn ($messages) => $messages
                ->where('sender_type', 'customer')
                ->whereNull('read_at'),
        ]);

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'linked_type' => ['nullable', 'string', 'max:80'],
            'linked_id' => ['nullable', 'integer'],
            'linked_label' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = $validated['user_id'] ?? null;
        if (!$userId && !empty($validated['email'])) {
            $user = \App\Models\User::where('email', $validated['email'])->first();
            $userId = $user?->id;
        }

        if (!$userId) {
            $userId = $request->user()?->id;
        }

        $now = Carbon::now();
        $conversation = EcommerceConversation::create([
            'user_id' => $userId,
            'subject' => $validated['subject'],
            'status' => 'open',
            'linked_type' => $validated['linked_type'] ?? null,
            'linked_id' => $validated['linked_id'] ?? null,
            'linked_label' => $validated['linked_label'] ?? 'Sales',
            'last_admin_message_at' => $now,
            'last_message_at' => $now,
        ]);

        $conversation->messages()->create([
            'sender_id' => $request->user()?->id,
            'sender_type' => 'admin',
            'message' => $validated['message'],
        ]);

        $conversation->load($this->conversationRelations());
        $conversation->loadCount([
            'messages',
            'messages as unread_customer_messages_count' => fn ($messages) => $messages
                ->where('sender_type', 'customer')
                ->whereNull('read_at'),
        ]);

        return response()->json(['data' => $this->conversationPayload($conversation)], 201);
    }

    public function updateCategory(Request $request, EcommerceConversation $conversation)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
        ]);

        $conversation->update([
            'linked_label' => $validated['category'],
        ]);

        $conversation->load($this->conversationRelations());
        $conversation->loadCount([
            'messages',
            'messages as unread_customer_messages_count' => fn ($messages) => $messages
                ->where('sender_type', 'customer')
                ->whereNull('read_at'),
        ]);

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function destroy(EcommerceConversation $conversation)
    {
        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json([
            'message' => 'Conversation deleted successfully.',
            'id' => $conversation->id,
        ]);
    }
}
