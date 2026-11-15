<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in member's in-app notifications (panel bell). */
class UserNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = UserNotification::where('user_id', $user->id)->latest();
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        $page = $query->paginate(max(1, min(50, (int) $request->query('per_page', 15))));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => UserNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['unread' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $n = UserNotification::where('user_id', $request->user()->id)->findOrFail($id);
        if (! $n->read_at) {
            $n->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'data' => $n]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => ['updated' => $count]]);
    }
}
