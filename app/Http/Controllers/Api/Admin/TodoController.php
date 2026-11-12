<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TodoController extends Controller
{
    /**
     * Display a listing of todos with metrics
     */
    public function index(Request $request)
    {
        $query = Todo::query()->orderBy('order_num', 'asc')->orderBy('id', 'desc');

        // Search filter
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('task_id', 'ilike', "%{$search}%");
            });
        }

        // Priority filter
        if ($request->filled('priority') && $request->input('priority') !== 'all') {
            $query->where('priority', strtolower($request->input('priority')));
        }

        // Status filter
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', strtolower($request->input('status')));
        }

        // Filter tabs (overdue, today, upcoming, completed)
        if ($request->filled('tab')) {
            $tab = $request->input('tab');
            $today = Carbon::today()->toDateString();
            if ($tab === 'overdue') {
                $query->where('status', '!=', 'completed')
                      ->where('due_date', '<', $today);
            } elseif ($tab === 'today') {
                $query->where('due_date', $today);
            } elseif ($tab === 'upcoming') {
                $query->where('status', '!=', 'completed')
                      ->where('due_date', '>', $today);
            } elseif ($tab === 'completed') {
                $query->where('status', 'completed');
            }
        }

        $todos = $query->get();

        // Calculate 100% Real Database KPI Metrics
        $allTodos = Todo::all();
        $totalCount = $allTodos->count();
        $completedCount = $allTodos->where('status', 'completed')->count();
        $inProgressCount = $allTodos->where('status', 'in_progress')->count();
        $pendingCount = $allTodos->where('status', 'pending')->count();
        $onHoldCount = $allTodos->where('status', 'on_hold')->count();

        $today = Carbon::today()->toDateString();

        $overdueCount = $allTodos->filter(function ($t) use ($today) {
            if ($t->status === 'completed') return false;
            if (empty($t->due_date)) return false;
            return $t->due_date < $today;
        })->count();

        $dueTodayCount = $allTodos->filter(function ($t) use ($today) {
            if ($t->status === 'completed') return false;
            return $t->due_date === $today;
        })->count();

        $upcomingCount = $allTodos->filter(function ($t) use ($today) {
            if ($t->status === 'completed') return false;
            return empty($t->due_date) || $t->due_date > $today;
        })->count();

        $totalPercentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;

        // Real Reminders from database tasks
        $remindersTasks = $allTodos->filter(function ($t) {
            if ($t->status === 'completed') return false;
            return !empty($t->reminder_time);
        })->sortBy('reminder_time')->take(6);

        $todaysReminders = [];
        foreach ($remindersTasks as $remTask) {
            $color = $remTask->priority === 'urgent' ? '#EF4444' : ($remTask->priority === 'normal' ? '#F59E0B' : '#10B981');
            $todaysReminders[] = [
                'id' => $remTask->id,
                'time' => $remTask->reminder_time ?: '09:00 AM',
                'title' => $remTask->reminder_title ?: $remTask->title,
                'color' => $color,
                'color_name' => $remTask->priority === 'urgent' ? 'red' : ($remTask->priority === 'normal' ? 'yellow' : 'green'),
            ];
        }

        $metrics = [
            'overdue_tasks' => $overdueCount,
            'due_today' => $dueTodayCount,
            'upcoming_tasks' => $upcomingCount,
            'completed_tasks' => $completedCount,
            'status_progress' => [
                'total_percentage' => $totalPercentage,
                'on_hold' => $onHoldCount,
                'pending' => $pendingCount,
                'in_progress' => $inProgressCount,
                'completed' => $completedCount,
            ],
            'priority_breakdown' => [
                'urgent' => $allTodos->where('priority', 'urgent')->count(),
                'normal' => $allTodos->where('priority', 'normal')->count(),
                'low' => $allTodos->where('priority', 'low')->count(),
            ],
            'todays_reminders' => $todaysReminders,
        ];

        return response()->json([
            'success' => true,
            'data' => $todos,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Store a newly created todo
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|in:urgent,normal,low',
            'status' => 'nullable|string|in:pending,in_progress,on_hold,completed',
            'due_date' => 'nullable|string',
            'reminder_date' => 'nullable|string',
            'reminder_time' => 'nullable|string',
            'assigned_to_name' => 'nullable|string',
            'assigned_to_email' => 'nullable|string',
            'assigned_to_avatar' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'attachments' => 'nullable|array',
        ]);

        // Generate next task_id
        $lastTodo = Todo::orderBy('id', 'desc')->first();
        $nextNum = $lastTodo ? ($lastTodo->id + 10325) : 10325;
        $taskId = 'TSK-' . $nextNum;

        $todo = Todo::create([
            'task_id' => $taskId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'priority' => $validated['priority'] ?? 'urgent',
            'status' => $validated['status'] ?? 'pending',
            'due_date' => !empty($validated['due_date']) ? Carbon::parse($validated['due_date'])->toDateString() : null,
            'reminder_date' => !empty($validated['reminder_date']) ? Carbon::parse($validated['reminder_date'])->toDateString() : null,
            'reminder_time' => $validated['reminder_time'] ?? '09:00 AM',
            'assigned_to_name' => $validated['assigned_to_name'] ?? null,
            'assigned_to_email' => $validated['assigned_to_email'] ?? null,
            'assigned_to_avatar' => $validated['assigned_to_avatar'] ?? null,
            'checklist_items' => $validated['checklist_items'] ?? [],
            'attachments' => $validated['attachments'] ?? [],
            'order_num' => ($lastTodo ? $lastTodo->order_num + 1 : 1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully',
            'data' => $todo,
        ], 201);
    }

    /**
     * Display the specified todo
     */
    public function show($id)
    {
        $todo = is_numeric($id) ? Todo::find($id) : Todo::where('task_id', $id)->first();
        if (!$todo) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $todo,
        ]);
    }

    /**
     * Update the specified todo
     */
    public function update(Request $request, $id)
    {
        $todo = is_numeric($id) ? Todo::find($id) : Todo::where('task_id', $id)->first();
        if (!$todo) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|in:urgent,normal,low',
            'status' => 'nullable|string|in:pending,in_progress,on_hold,completed',
            'due_date' => 'nullable|string',
            'reminder_date' => 'nullable|string',
            'reminder_time' => 'nullable|string',
            'assigned_to_name' => 'nullable|string',
            'assigned_to_email' => 'nullable|string',
            'assigned_to_avatar' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'attachments' => 'nullable|array',
        ]);

        if (isset($validated['due_date'])) {
            $validated['due_date'] = !empty($validated['due_date']) ? Carbon::parse($validated['due_date'])->toDateString() : null;
        }
        if (isset($validated['reminder_date'])) {
            $validated['reminder_date'] = !empty($validated['reminder_date']) ? Carbon::parse($validated['reminder_date'])->toDateString() : null;
        }

        $todo->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully',
            'data' => $todo->fresh(),
        ]);
    }

    /**
     * Toggle checklist item
     */
    public function toggleChecklist(Request $request, $id, $itemIndex)
    {
        $todo = is_numeric($id) ? Todo::find($id) : Todo::where('task_id', $id)->first();
        if (!$todo) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        $items = $todo->checklist_items ?? [];
        $idx = (int)$itemIndex;

        if (isset($items[$idx])) {
            $items[$idx]['completed'] = !($items[$idx]['completed'] ?? false);
            $todo->checklist_items = $items;

            // Calculate auto-status if all completed or partially completed
            $total = count($items);
            $completed = count(array_filter($items, fn($it) => !empty($it['completed'])));
            if ($total > 0 && $completed === $total) {
                $todo->status = 'completed';
            } elseif ($completed > 0 && $todo->status === 'pending') {
                $todo->status = 'in_progress';
            }
            $todo->save();
        }

        return response()->json([
            'success' => true,
            'data' => $todo,
        ]);
    }

    /**
     * Quick status update
     */
    public function updateStatus(Request $request, $id)
    {
        $todo = is_numeric($id) ? Todo::find($id) : Todo::where('task_id', $id)->first();
        if (!$todo) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        $status = strtolower($request->input('status', 'completed'));
        $todo->status = $status;
        $todo->save();

        return response()->json([
            'success' => true,
            'data' => $todo,
        ]);
    }

    /**
     * Delete the specified todo
     */
    public function destroy($id)
    {
        $todo = is_numeric($id) ? Todo::find($id) : Todo::where('task_id', $id)->first();
        if (!$todo) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        $todo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully',
        ]);
    }

    /**
     * Handle attachment file upload
     */
    public function uploadAttachment(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        $sizeBytes = $file->getSize();
        $sizeMb = round($sizeBytes / (1024 * 1024), 1);
        $sizeStr = $sizeMb >= 1 ? "{$sizeMb} MB" : round($sizeBytes / 1024) . " KB";

        $filename = 'todo_' . time() . '_' . Str::random(6) . '.' . $ext;
        $file->move(public_path('storage/todos'), $filename);

        $url = '/storage/todos/' . $filename;
        $type = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'doc');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => time(),
                'name' => $originalName,
                'size' => $sizeStr,
                'type' => $type,
                'url' => $url,
                'thumbnail' => $type === 'image' ? $url : '/assets/images/todo/pdf_icon.png',
            ]
        ]);
    }

    /**
     * Get real staff users for task assignment
     */
    public function getStaffUsers()
    {
        $allowedRoles = ['super_admin', 'admin', 'manager', 'staff', 'editor'];

        $staff = \App\Models\User::whereIn('role', $allowedRoles)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'email', 'role', 'department', 'job_title', 'avatar']);

        return response()->json([
            'success' => true,
            'data' => $staff,
        ]);
    }
}
