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
     * Default tasks matching the design screenshot
     */
    public static function getDefaultTasks()
    {
        return [
            [
                'task_id' => 'TSK-10325',
                'title' => 'Send proof to customer',
                'description' => 'Prepare the final artwork proof with the updated logo placement and colors. Send to customer for review and approval. Once approved, proceed to create the production sheet.',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'due_date' => '2026-09-28',
                'reminder_date' => '2026-09-28',
                'reminder_time' => '09:00 AM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Attach final artwork', 'completed' => true],
                    ['id' => 2, 'text' => 'Confirm logo placement and colors', 'completed' => true],
                    ['id' => 3, 'text' => 'Send proof to customer', 'completed' => true],
                    ['id' => 4, 'text' => 'Get customer approval', 'completed' => false],
                    ['id' => 5, 'text' => 'Follow up if no response within 24 hours', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Cap_Sample.jpg',
                        'size' => '2.8 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/cap_sample_lg.png',
                        'thumbnail' => '/assets/images/todo/cap_black.png'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Artwork_Logo.pdf',
                        'size' => '1.2 MB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_sample_lg.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ],
                    [
                        'id' => 3,
                        'name' => 'Cap_White_Sample.jpg',
                        'size' => '1.9 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/cap_white.png',
                        'thumbnail' => '/assets/images/todo/cap_white.png'
                    ],
                    [
                        'id' => 4,
                        'name' => 'Sample_Spec.docx',
                        'size' => '856 KB',
                        'type' => 'doc',
                        'url' => '/assets/images/todo/chip_word.png',
                        'thumbnail' => '/assets/images/todo/chip_word.png'
                    ],
                ],
                'order_num' => 1,
            ],
            [
                'task_id' => 'TSK-10326',
                'title' => 'Approve thread colors',
                'description' => 'Review and approve thread color chart for order #ME-10310.',
                'priority' => 'normal',
                'status' => 'in_progress',
                'due_date' => '2026-09-29',
                'reminder_date' => '2026-09-29',
                'reminder_time' => '11:00 AM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Review color chart', 'completed' => true],
                    ['id' => 2, 'text' => 'Confirm thread codes', 'completed' => true],
                    ['id' => 3, 'text' => 'Send to customer', 'completed' => false],
                    ['id' => 4, 'text' => 'Update production notes', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Thread_Color.jpg',
                        'size' => '1.1 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/threads.png',
                        'thumbnail' => '/assets/images/todo/threads.png'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Pantone_Threads.png',
                        'size' => '2.1 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/threads.png',
                        'thumbnail' => '/assets/images/todo/threads.png'
                    ],
                    [
                        'id' => 3,
                        'name' => 'Color_Code_Sheet.pdf',
                        'size' => '620 KB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_icon.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ]
                ],
                'order_num' => 2,
            ],
            [
                'task_id' => 'TSK-10327',
                'title' => 'Follow up with supplier',
                'description' => 'Check ETA for hat shipment and confirm tracking information.',
                'priority' => 'low',
                'status' => 'pending',
                'due_date' => '2026-09-30',
                'reminder_date' => '2026-09-30',
                'reminder_time' => '02:00 PM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Contact supplier', 'completed' => true],
                    ['id' => 2, 'text' => 'Check ETA', 'completed' => false],
                    ['id' => 3, 'text' => 'Confirm tracking number', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Shipment_Box.jpg',
                        'size' => '940 KB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/box.png',
                        'thumbnail' => '/assets/images/todo/box.png'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Packing_Manifest.png',
                        'size' => '1.3 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/packing.png',
                        'thumbnail' => '/assets/images/todo/packing.png'
                    ],
                    [
                        'id' => 3,
                        'name' => 'Customs_Clearance.pdf',
                        'size' => '420 KB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_icon.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ]
                ],
                'order_num' => 3,
            ],
            [
                'task_id' => 'TSK-10328',
                'title' => 'Prepare production sheet',
                'description' => 'Compile final designs, materials and quantity for production.',
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => '2026-10-01',
                'reminder_date' => '2026-10-01',
                'reminder_time' => '04:00 PM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Add designs to sheet', 'completed' => true],
                    ['id' => 2, 'text' => 'Confirm quantities', 'completed' => true],
                    ['id' => 3, 'text' => 'Review materials', 'completed' => false],
                    ['id' => 4, 'text' => 'Send to production', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Blue_Polo_Front.jpg',
                        'size' => '1.5 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/polo1.png',
                        'thumbnail' => '/assets/images/todo/polo1.png'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Blue_Polo_Back.jpg',
                        'size' => '1.4 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/polo2.png',
                        'thumbnail' => '/assets/images/todo/polo2.png'
                    ],
                    [
                        'id' => 3,
                        'name' => 'Size_Grid.xlsx',
                        'size' => '320 KB',
                        'type' => 'doc',
                        'url' => '/assets/images/todo/chip_word.png',
                        'thumbnail' => '/assets/images/todo/chip_word.png'
                    ],
                    [
                        'id' => 4,
                        'name' => 'Material_Spec.pdf',
                        'size' => '1.1 MB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_icon.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ],
                    [
                        'id' => 5,
                        'name' => 'Stitch_Count.pdf',
                        'size' => '780 KB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_icon.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ]
                ],
                'order_num' => 4,
            ],
            [
                'task_id' => 'TSK-10329',
                'title' => 'Create quotation',
                'description' => 'Prepare quotation for school uniform order including embroidery and setup fees.',
                'priority' => 'normal',
                'status' => 'on_hold',
                'due_date' => '2026-10-03',
                'reminder_date' => '2026-10-03',
                'reminder_time' => '02:00 PM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Add products and pricing', 'completed' => true],
                    ['id' => 2, 'text' => 'Include setup fees', 'completed' => false],
                    ['id' => 3, 'text' => 'Send to customer', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Quotation_ME-10310.pdf',
                        'size' => '248 KB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_sample_lg.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ]
                ],
                'order_num' => 5,
            ],
            [
                'task_id' => 'TSK-10330',
                'title' => 'Schedule delivery',
                'description' => 'Arrange delivery with courier and update tracking information.',
                'priority' => 'low',
                'status' => 'completed',
                'due_date' => '2026-10-05',
                'reminder_date' => '2026-10-05',
                'reminder_time' => '10:00 AM',
                'assigned_to_name' => 'Jason Davis',
                'assigned_to_email' => 'jason@mecarvi.com',
                'assigned_to_avatar' => 'JD',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Book courier', 'completed' => true],
                    ['id' => 2, 'text' => 'Share tracking with customer', 'completed' => true],
                    ['id' => 3, 'text' => 'Confirm delivery date', 'completed' => false],
                    ['id' => 4, 'text' => 'Update order status', 'completed' => false],
                ],
                'attachments' => [
                    [
                        'id' => 1,
                        'name' => 'Delivery_Van.jpg',
                        'size' => '1.7 MB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/van.png',
                        'thumbnail' => '/assets/images/todo/van.png'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Dropoff_Location.png',
                        'size' => '950 KB',
                        'type' => 'image',
                        'url' => '/assets/images/todo/map.png',
                        'thumbnail' => '/assets/images/todo/map.png'
                    ],
                    [
                        'id' => 3,
                        'name' => 'Waybill_Receipt.pdf',
                        'size' => '310 KB',
                        'type' => 'pdf',
                        'url' => '/assets/images/todo/pdf_icon.png',
                        'thumbnail' => '/assets/images/todo/pdf_icon.png'
                    ]
                ],
                'order_num' => 6,
            ],
        ];
    }

    /**
     * Display a listing of todos with metrics
     */
    public function index(Request $request)
    {
        // Auto-seed default tasks if table is empty
        if (Todo::count() === 0) {
            foreach (self::getDefaultTasks() as $taskData) {
                Todo::create($taskData);
            }
        }

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
            'assigned_to_name' => $validated['assigned_to_name'] ?? 'Jason Davis',
            'assigned_to_email' => $validated['assigned_to_email'] ?? 'jason@mecarvi.com',
            'assigned_to_avatar' => $validated['assigned_to_avatar'] ?? 'JD',
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

    /**
     * Reset / Re-seed default tasks
     */
    public function resetDefaults()
    {
        Todo::truncate();
        foreach (self::getDefaultTasks() as $taskData) {
            Todo::create($taskData);
        }

        return response()->json([
            'success' => true,
            'message' => 'Default tasks restored successfully',
            'data' => Todo::all(),
        ]);
    }
}
