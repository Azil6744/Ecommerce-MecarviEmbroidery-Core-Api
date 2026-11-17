<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminFile;
use App\Models\AdminFileFolder;
use App\Models\FmActivity;
use App\Models\FmTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin File Manager: departments, categories, folders / sub folders, files and trash.
 * Files live on the private "local" disk and are only reachable through authenticated endpoints.
 * Department / category icons are non-sensitive and live on the "public" disk.
 */
class AdminFileManagerController extends Controller
{
    private const TONES = ['pink', 'blue', 'yellow', 'purple', 'green', 'orange', 'cyan', 'red'];
    private const TRASH_DAYS = 30;

    private const ALLOWED_EXTENSIONS = [
        'dst', 'pes', 'exp', 'jef', 'vp3', 'hus', 'emb', 'ai', 'eps', 'svg', 'psd',
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        'xlsx', 'xls', 'csv', 'docx', 'doc', 'txt',
        'zip', 'rar', 'mp4', 'mov', 'avi', 'mkv', 'webm', 'mp3', 'wav', 'ogg', 'm4a',
    ];

    /** Quick-access / storage groups by extension. */
    private const TYPE_GROUPS = [
        'images' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
        'documents' => ['pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx', 'csv'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'apps' => ['zip', 'rar', 'exe', 'apk', 'dmg'],
        'videos' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
    ];

    /* ------------------------------------------------------------------ */
    /* Dashboard                                                          */
    /* ------------------------------------------------------------------ */

    public function dashboard(): JsonResponse
    {
        $stats = $this->folderStats();
        $names = $this->folderNameMap();

        $depts = FmTaxonomy::where('kind', 'department')->orderBy('name')->get();
        $cats = FmTaxonomy::where('kind', 'category')->orderBy('name')->get();

        $roots = AdminFileFolder::whereNull('parent_id')->orderBy('name')->get();
        $folders = $roots->map(function (AdminFileFolder $f) use ($stats, $names) {
            $payload = $this->folderPayload($f, $stats, $names);
            $payload['children'] = AdminFileFolder::where('parent_id', $f->id)->orderBy('name')->limit(4)->get()
                ->map(fn ($c) => $this->folderPayload($c, $stats, $names))->values();

            return $payload;
        })->values();

        $byExt = AdminFile::query()->select('extension', DB::raw('count(*) as c'), DB::raw('coalesce(sum(size),0) as b'))
            ->groupBy('extension')->get();
        $quick = [];
        $storage = [];
        $grouped = [];
        foreach (self::TYPE_GROUPS as $group => $exts) {
            $rows = $byExt->filter(fn ($r) => in_array(strtolower((string) $r->extension), $exts, true));
            $quick[] = ['key' => $group, 'count' => (int) $rows->sum('c'), 'bytes' => (int) $rows->sum('b')];
            $storage[] = ['key' => $group, 'bytes' => (int) $rows->sum('b')];
            $grouped = array_merge($grouped, $exts);
        }
        $other = $byExt->reject(fn ($r) => in_array(strtolower((string) $r->extension), $grouped, true));
        $storage[] = ['key' => 'other', 'bytes' => (int) $other->sum('b')];
        $used = (int) AdminFile::sum('size');

        return response()->json(['success' => true, 'data' => [
            'stats' => [
                'departments' => $depts->count(),
                'categories' => $cats->count(),
                'folders' => AdminFileFolder::count(),
                'files' => AdminFile::count(),
            ],
            'departments' => $depts->map(fn ($d) => $this->taxonomyPayload($d))->values(),
            'categories' => $cats->map(fn ($d) => $this->taxonomyPayload($d))->values(),
            'folders' => $folders,
            'quick_access' => $quick,
            'storage' => ['used_bytes' => $used, 'limit_bytes' => $this->storageLimit(), 'breakdown' => $storage],
            'activity' => FmActivity::latest('id')->limit(8)->get()->map(fn ($a) => [
                'id' => $a->id,
                'actor' => $a->actor_name ?: 'Admin',
                'action' => $a->action,
                'entity_type' => $a->entity_type,
                'entity_name' => $a->entity_name,
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])->values(),
            'trash_counts' => $this->trashCounts(),
        ]]);
    }

    /* ------------------------------------------------------------------ */
    /* Departments & categories                                           */
    /* ------------------------------------------------------------------ */

    public function taxonomyIndex(Request $request, string $kind): JsonResponse
    {
        $kind = $this->kind($kind);
        $q = FmTaxonomy::where('kind', $kind);
        if ($s = trim((string) $request->input('search', ''))) {
            $q->where('name', 'ilike', "%{$s}%");
        }
        $page = $q->orderBy('name')->paginate((int) $request->input('per_page', 12));
        $usage = $this->taxonomyUsage($kind);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($t) => $this->taxonomyPayload($t, $usage))->values(),
            'meta' => $this->meta($page) + [
                'total_files' => (int) collect($usage)->sum('files'),
                'total_bytes' => (int) collect($usage)->sum('bytes'),
            ],
        ]);
    }

    public function taxonomyStore(Request $request, string $kind): JsonResponse
    {
        $kind = $this->kind($kind);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('fm_taxonomies', 'name')->where('kind', $kind)],
            'is_active' => 'nullable|boolean',
            'icon_key' => 'nullable|string|max:40',
            'icon' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ]);

        $t = FmTaxonomy::create([
            'kind' => $kind,
            'name' => trim($v['name']),
            'is_active' => $request->boolean('is_active', true),
            'icon_key' => $v['icon_key'] ?? null,
            'tone' => self::TONES[FmTaxonomy::where('kind', $kind)->count() % count(self::TONES)],
            'icon_path' => $request->hasFile('icon') ? $request->file('icon')->store('fm-icons', 'public') : null,
            'created_by' => optional($request->user())->id,
        ]);
        $this->log($request, 'created', $kind, $t->id, $t->name);

        return response()->json(['success' => true, 'data' => $this->taxonomyPayload($t)], 201);
    }

    public function taxonomyUpdate(Request $request, string $kind, int $id): JsonResponse
    {
        $kind = $this->kind($kind);
        $t = FmTaxonomy::where('kind', $kind)->findOrFail($id);
        $v = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('fm_taxonomies', 'name')->where('kind', $kind)->ignore($t->id)],
            'is_active' => 'nullable|boolean',
            'remove_icon' => 'nullable|boolean',
            'icon' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ]);

        if (isset($v['name'])) $t->name = trim($v['name']);
        if ($request->has('is_active')) $t->is_active = $request->boolean('is_active');
        if ($request->hasFile('icon')) {
            $this->deleteIcon($t);
            $t->icon_path = $request->file('icon')->store('fm-icons', 'public');
        } elseif ($request->boolean('remove_icon')) {
            $this->deleteIcon($t);
            $t->icon_path = null;
        }
        $t->save();
        $this->log($request, 'updated', $kind, $t->id, $t->name);

        return response()->json(['success' => true, 'data' => $this->taxonomyPayload($t)]);
    }

    public function taxonomyDestroy(Request $request, string $kind, int $id): JsonResponse
    {
        $kind = $this->kind($kind);
        $t = FmTaxonomy::where('kind', $kind)->findOrFail($id);
        $this->deleteIcon($t);
        $name = $t->name;
        $t->delete(); // files / folders keep existing; FK nulls their link
        $this->log($request, 'deleted', $kind, $id, $name);

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Folders                                                            */
    /* ------------------------------------------------------------------ */

    /** GET folders?parent_id=root|<id>|all&search=&page=&per_page=&with_children=1 */
    public function folderIndex(Request $request): JsonResponse
    {
        $parent = $request->input('parent_id', 'root');
        $q = AdminFileFolder::query();
        if ($parent === 'root' || $parent === '' || $parent === null) $q->whereNull('parent_id');
        elseif ($parent !== 'all') $q->where('parent_id', (int) $parent);
        if ($s = trim((string) $request->input('search', ''))) {
            $q->where('name', 'ilike', "%{$s}%");
        }

        $page = $q->orderBy('name')->paginate((int) $request->input('per_page', 12));
        $stats = $this->folderStats();
        $names = $this->folderNameMap();
        $withChildren = $request->boolean('with_children');

        $items = $page->getCollection()->map(function (AdminFileFolder $f) use ($stats, $names, $withChildren) {
            $p = $this->folderPayload($f, $stats, $names);
            if ($withChildren) {
                $p['children'] = AdminFileFolder::where('parent_id', $f->id)->orderBy('name')->get()
                    ->map(fn ($c) => $this->folderPayload($c, $stats, $names))->values();
            }

            return $p;
        })->values();

        $summary = null;
        if ($parent !== 'root' && $parent !== 'all' && $parent !== '' && $parent !== null) {
            $self = AdminFileFolder::findOrFail((int) $parent);
            if ($locked = $this->checkFolderAccess($self, $request->input('password'))) return $locked;
            $summary = $this->folderPayload($self, $stats, $names);
        }

        return response()->json([
            'success' => true,
            'data' => $items,
            'folder' => $summary,
            'meta' => $this->meta($page) + [
                'total_bytes' => (int) AdminFile::sum('size'),
            ],
        ]);
    }

    public function folderStore(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:admin_file_folders,id',
            'department_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'is_protected' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:100|confirmed',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:4096',
        ]);
        $name = trim($v['name']);
        $parentId = $v['parent_id'] ?? null;
        $this->assertUniqueFolderName($name, $parentId);
        $protected = $request->boolean('is_protected');
        if ($protected && empty($v['password'])) {
            return $this->fail('A password is required for a protected folder.', 422);
        }

        $folder = AdminFileFolder::create([
            'name' => $name,
            'parent_id' => $parentId,
            'department_id' => $v['department_id'] ?? null,
            'tone' => self::TONES[AdminFileFolder::withTrashed()->count() % count(self::TONES)],
            'image_path' => $request->hasFile('image') ? $request->file('image')->store('fm-folders', 'public') : null,
            'is_protected' => $protected,
            'password_hash' => $protected ? Hash::make($v['password']) : null,
            'is_active' => $request->boolean('is_active', true),
            'created_by' => optional($request->user())->id,
        ]);
        $this->log($request, 'created', 'folder', $folder->id, $folder->name);

        return response()->json(['success' => true, 'data' => $this->folderPayload($folder, $this->folderStats(), $this->folderNameMap())], 201);
    }

    public function folderUpdate(Request $request, int $id): JsonResponse
    {
        $folder = AdminFileFolder::findOrFail($id);
        $v = $request->validate([
            'name' => 'sometimes|string|max:120',
            'department_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'is_protected' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:100|confirmed',
            'current_password' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'remove_image' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:4096',
        ]);

        // Changing the lock or password on an already protected folder requires the current password.
        $changingProtection = $request->has('is_protected') && $request->boolean('is_protected') !== (bool) $folder->is_protected;
        $touchesLock = $changingProtection || ! empty($v['password']);
        if ($folder->is_protected && $touchesLock && ! Hash::check((string) $request->input('current_password'), (string) $folder->password_hash)) {
            return $this->fail('The current folder password is incorrect.', 403);
        }

        if (isset($v['name'])) {
            $name = trim($v['name']);
            $this->assertUniqueFolderName($name, $folder->parent_id, $folder->id);
            $folder->name = $name;
        }
        if ($request->has('department_id')) $folder->department_id = $v['department_id'];
        if ($request->has('is_active')) $folder->is_active = $request->boolean('is_active');
        if ($request->hasFile('image')) {
            if ($folder->image_path) Storage::disk('public')->delete($folder->image_path);
            $folder->image_path = $request->file('image')->store('fm-folders', 'public');
        } elseif ($request->boolean('remove_image')) {
            if ($folder->image_path) Storage::disk('public')->delete($folder->image_path);
            $folder->image_path = null;
        }
        if ($request->has('is_protected')) {
            if ($request->boolean('is_protected')) {
                if (empty($v['password']) && ! $folder->password_hash) return $this->fail('A password is required for a protected folder.', 422);
                $folder->is_protected = true;
            } else {
                $folder->is_protected = false;
                $folder->password_hash = null;
            }
        }
        if ($folder->is_protected && ! empty($v['password'])) $folder->password_hash = Hash::make($v['password']);
        $folder->save();
        $this->log($request, 'updated', 'folder', $folder->id, $folder->name);

        return response()->json(['success' => true, 'data' => $this->folderPayload($folder, $this->folderStats(), $this->folderNameMap())]);
    }

    public function folderUnlock(Request $request, int $id): JsonResponse
    {
        $folder = AdminFileFolder::findOrFail($id);
        $request->validate(['password' => 'required|string']);
        if ($locked = $this->checkFolderAccess($folder, $request->input('password'))) return $locked;

        return response()->json(['success' => true]);
    }

    /** Move a folder (and everything in it) to the trash. */
    public function folderDestroy(Request $request, int $id): JsonResponse
    {
        $folder = AdminFileFolder::findOrFail($id);
        if ($folder->is_protected && ! Hash::check((string) $request->input('password'), (string) $folder->password_hash)) {
            return $this->fail('Enter the folder password to delete it.', 403);
        }

        $batch = (string) Str::uuid();
        $ids = $this->descendantIds($folder->id);
        DB::transaction(function () use ($ids, $folder, $batch) {
            AdminFile::whereIn('folder_id', $ids)->update(['deleted_at' => now(), 'trash_batch' => $batch]);
            AdminFileFolder::whereIn('id', $ids)->update(['deleted_at' => now(), 'trash_batch' => $batch]);
            AdminFileFolder::withTrashed()->where('id', $folder->id)->update(['trash_root' => true]);
        });
        $this->log($request, 'deleted', 'folder', $folder->id, $folder->name);

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Files                                                              */
    /* ------------------------------------------------------------------ */

    /** GET files?folder_id=&type=images|documents|...&search=&page=&per_page= */
    public function fileIndex(Request $request): JsonResponse
    {
        $q = AdminFile::query()->with(['folder:id,name,parent_id', 'department:id,name', 'category:id,name']);

        if ($request->filled('folder_id')) {
            $folder = AdminFileFolder::findOrFail((int) $request->input('folder_id'));
            if ($locked = $this->checkFolderAccess($folder, $request->input('password'))) return $locked;
            $q->where('folder_id', $folder->id);
        }
        if ($type = $request->input('type')) {
            if (isset(self::TYPE_GROUPS[$type])) $q->whereIn(DB::raw('lower(extension)'), self::TYPE_GROUPS[$type]);
        }
        if ($request->filled('department_id')) $q->where('department_id', (int) $request->input('department_id'));
        if ($request->filled('category_id')) $q->where('category_id', (int) $request->input('category_id'));
        if ($s = trim((string) $request->input('search', ''))) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%"));
        }

        $page = $q->latest('id')->paginate((int) $request->input('per_page', 12));
        $names = $this->folderNameMap();

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($f) => $this->filePayload($f, $names))->values(),
            'meta' => $this->meta($page),
        ]);
    }

    public function fileShow(int $id): JsonResponse
    {
        $f = AdminFile::with(['folder:id,name,parent_id', 'department:id,name', 'category:id,name'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->filePayload($f, $this->folderNameMap())]);
    }

    /** Upload one file with metadata (`file`) or several quick uploads (`files[]`). */
    public function fileStore(Request $request): JsonResponse
    {
        $v = $request->validate([
            'file' => 'required_without:files|file|max:51200',
            'files' => 'required_without:file|array|min:1|max:20',
            'files.*' => 'file|max:51200', // 50 MB each
            'name' => 'nullable|string|max:250',
            'description' => 'nullable|string|max:2000',
            'folder_id' => 'nullable|integer|exists:admin_file_folders,id',
            'department_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'category_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'is_protected' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:100|confirmed',
            'is_active' => 'nullable|boolean',
        ]);

        $protected = $request->boolean('is_protected');
        if ($protected && empty($v['password'])) return $this->fail('A password is required for a protected file.', 422);

        $uploads = $request->hasFile('file') ? [$request->file('file')] : $request->file('files', []);
        foreach ($uploads as $u) {
            $ext = strtolower($u->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                return $this->fail("Files of type .{$ext} are not allowed.", 422);
            }
        }

        $saved = [];
        foreach ($uploads as $u) {
            $ext = strtolower($u->getClientOriginalExtension());
            $original = Str::limit($u->getClientOriginalName(), 250, '');
            $display = $request->hasFile('file') && ! empty($v['name']) ? trim($v['name']) : $original;
            if ($ext && ! Str::endsWith(strtolower($display), '.' . $ext)) $display .= '.' . $ext;

            $path = $u->storeAs('admin-files/' . now()->format('Y/m'), Str::uuid() . '.' . $ext, 'local');
            $file = AdminFile::create([
                'folder_id' => $v['folder_id'] ?? null,
                'name' => Str::limit($display, 250, ''),
                'original_name' => $original,
                'description' => $v['description'] ?? null,
                'path' => $path,
                'disk' => 'local',
                'size' => $u->getSize(),
                'mime_type' => $u->getClientMimeType(),
                'extension' => $ext,
                'department_id' => $v['department_id'] ?? null,
                'category_id' => $v['category_id'] ?? null,
                'is_protected' => $protected,
                'password_hash' => $protected ? Hash::make($v['password']) : null,
                'is_active' => $request->boolean('is_active', true),
                'uploaded_by' => optional($request->user())->id,
                'uploaded_by_name' => $this->actor($request),
            ]);
            $this->log($request, 'uploaded', 'file', $file->id, $file->name);
            $saved[] = $file->load(['folder:id,name,parent_id', 'department:id,name', 'category:id,name']);
        }

        $names = $this->folderNameMap();

        return response()->json([
            'success' => true,
            'data' => collect($saved)->map(fn ($f) => $this->filePayload($f, $names))->values(),
        ], 201);
    }

    /** Rename / edit metadata / change password. */
    public function fileUpdate(Request $request, int $id): JsonResponse
    {
        $file = AdminFile::findOrFail($id);
        $v = $request->validate([
            'name' => 'sometimes|string|max:250',
            'description' => 'nullable|string|max:2000',
            'department_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'category_id' => 'nullable|integer|exists:fm_taxonomies,id',
            'is_protected' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:100|confirmed',
            'current_password' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $touchesLock = $request->has('is_protected') || ! empty($v['password']);
        if ($file->is_protected && $touchesLock && ! Hash::check((string) $request->input('current_password'), (string) $file->password_hash)) {
            return $this->fail('The current file password is incorrect.', 403);
        }

        if (isset($v['name'])) {
            $name = trim($v['name']);
            if ($file->extension && ! Str::endsWith(strtolower($name), '.' . strtolower($file->extension))) $name .= '.' . $file->extension;
            $file->name = $name;
        }
        foreach (['description', 'department_id', 'category_id'] as $k) {
            if ($request->has($k)) $file->{$k} = $v[$k] ?? null;
        }
        if ($request->has('is_active')) $file->is_active = $request->boolean('is_active');
        if ($request->has('is_protected')) {
            if ($request->boolean('is_protected')) {
                if (empty($v['password']) && ! $file->password_hash) return $this->fail('A password is required for a protected file.', 422);
                $file->is_protected = true;
            } else {
                $file->is_protected = false;
                $file->password_hash = null;
            }
        }
        if ($file->is_protected && ! empty($v['password'])) $file->password_hash = Hash::make($v['password']);
        $file->save();
        $this->log($request, 'updated', 'file', $file->id, $file->name);

        return response()->json(['success' => true, 'data' => $this->filePayload($file->fresh(['folder:id,name,parent_id', 'department:id,name', 'category:id,name']), $this->folderNameMap())]);
    }

    public function fileMove(Request $request, int $id): JsonResponse
    {
        $file = AdminFile::findOrFail($id);
        $v = $request->validate(['folder_id' => 'nullable|integer|exists:admin_file_folders,id']);
        $file->update(['folder_id' => $v['folder_id'] ?? null]);
        $this->log($request, 'updated', 'file', $file->id, $file->name);

        return response()->json(['success' => true, 'data' => $this->filePayload($file->fresh(['folder:id,name,parent_id', 'department:id,name', 'category:id,name']), $this->folderNameMap())]);
    }

    public function fileCopy(Request $request, int $id): JsonResponse
    {
        $file = AdminFile::findOrFail($id);
        $v = $request->validate(['folder_id' => 'nullable|integer|exists:admin_file_folders,id']);
        if (! Storage::disk($file->disk)->exists($file->path)) return $this->fail('File not found on storage.', 404);

        $newPath = 'admin-files/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $file->extension;
        Storage::disk($file->disk)->copy($file->path, $newPath);
        $copy = $file->replicate();
        $copy->path = $newPath;
        $copy->folder_id = $v['folder_id'] ?? $file->folder_id;
        $copy->name = preg_replace('/(\.[^.]+)?$/', ' (copy)$1', $file->name, 1);
        $copy->uploaded_by = optional($request->user())->id;
        $copy->uploaded_by_name = $this->actor($request);
        $copy->save();
        $this->log($request, 'created', 'file', $copy->id, $copy->name);

        return response()->json(['success' => true, 'data' => $this->filePayload($copy->load(['folder:id,name,parent_id', 'department:id,name', 'category:id,name']), $this->folderNameMap())], 201);
    }

    /** Stream the file (inline preview with ?inline=1, otherwise download). Accepts GET or POST (password). */
    public function fileContent(Request $request, int $id)
    {
        $file = AdminFile::findOrFail($id);
        if ($locked = $this->checkFileAccess($file, $request->input('password'))) return $locked;
        if (! Storage::disk($file->disk)->exists($file->path)) return $this->fail('File not found on storage.', 404);

        if ($request->boolean('inline')) {
            return Storage::disk($file->disk)->response($file->path, $file->name, [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            ]);
        }

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    /** Move a file to the trash. */
    public function fileDestroy(Request $request, int $id): JsonResponse
    {
        $file = AdminFile::findOrFail($id);
        if ($file->is_protected && ! Hash::check((string) $request->input('password'), (string) $file->password_hash)) {
            return $this->fail('Enter the file password to delete it.', 403);
        }
        $file->update(['trash_root' => true, 'trash_batch' => (string) Str::uuid()]);
        $file->delete();
        $this->log($request, 'deleted', 'file', $file->id, $file->name);

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Trash                                                              */
    /* ------------------------------------------------------------------ */

    /** GET trash?type=folders|subfolders|files&search=&page= */
    public function trashIndex(Request $request): JsonResponse
    {
        $rawType = $request->input('type', 'folders');
        $type = in_array($rawType, ['folders', 'subfolders', 'files'], true) ? $rawType : 'folders';
        $search = trim((string) $request->input('search', ''));
        $names = $this->folderNameMap(true);

        if ($type === 'folders' || $type === 'subfolders') {
            $q = AdminFileFolder::onlyTrashed()->where('trash_root', true);
            $type === 'folders' ? $q->whereNull('parent_id') : $q->whereNotNull('parent_id');
            if ($search) $q->where('name', 'ilike', "%{$search}%");
            $page = $q->orderByDesc('deleted_at')->paginate((int) $request->input('per_page', 8));
            $items = $page->getCollection()->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'tone' => $f->tone ?: 'pink',
                'image_url' => $f->image_path ? '/storage/' . ltrim($f->image_path, '/') : null,
                'path' => $this->pathFor($f, $names), 'deleted_at' => optional($f->deleted_at)->toIso8601String(),
            ]);
        } else {
            $q = AdminFile::onlyTrashed()->where('trash_root', true);
            if ($search) $q->where('name', 'ilike', "%{$search}%");
            $page = $q->orderByDesc('deleted_at')->paginate((int) $request->input('per_page', 8));
            $items = $page->getCollection()->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'extension' => strtoupper((string) $f->extension),
                'size' => (int) $f->size, 'mime_type' => $f->mime_type,
                'path' => $f->folder_id ? $this->pathFor($f->folder_id, $names, true) : '/',
                'deleted_at' => optional($f->deleted_at)->toIso8601String(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $items->values(), 'meta' => $this->meta($page) + ['counts' => $this->trashCounts()]]);
    }

    public function trashRestore(Request $request): JsonResponse
    {
        $v = $request->validate(['type' => 'required|in:folders,subfolders,files', 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $n = 0;
        foreach ($v['ids'] as $id) {
            if ($v['type'] === 'folders' || $v['type'] === 'subfolders') {
                $f = AdminFileFolder::onlyTrashed()->where('trash_root', true)->find($id);
                if (! $f) continue;
                $this->restoreFolderBatch($f);
                $this->log($request, 'restored', 'folder', $f->id, $f->name);
            } else {
                $f = AdminFile::onlyTrashed()->where('trash_root', true)->find($id);
                if (! $f) continue;
                $folderOk = $f->folder_id && AdminFileFolder::whereKey($f->folder_id)->exists();
                $f->update(['deleted_at' => null, 'trash_root' => false, 'trash_batch' => null, 'folder_id' => $folderOk ? $f->folder_id : null]);
                $this->log($request, 'restored', 'file', $f->id, $f->name);
            }
            $n++;
        }

        return response()->json(['success' => true, 'restored' => $n]);
    }

    public function trashPurge(Request $request): JsonResponse
    {
        $v = $request->validate(['type' => 'required|in:folders,subfolders,files', 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $n = 0;
        foreach ($v['ids'] as $id) {
            if ($v['type'] === 'folders' || $v['type'] === 'subfolders') {
                $f = AdminFileFolder::onlyTrashed()->where('trash_root', true)->find($id);
                if ($f) { $this->purgeFolderBatch($f); $n++; }
            } else {
                $f = AdminFile::onlyTrashed()->where('trash_root', true)->find($id);
                if ($f) { $this->purgeFile($f); $n++; }
            }
        }

        return response()->json(['success' => true, 'deleted' => $n]);
    }

    /** Permanently remove trash older than 30 days (called by `filemanager:purge-trash`). */
    public function purgeExpired(): int
    {
        $cut = now()->subDays(self::TRASH_DAYS);
        $n = 0;
        foreach (AdminFileFolder::onlyTrashed()->where('trash_root', true)->where('deleted_at', '<', $cut)->get() as $f) { $this->purgeFolderBatch($f); $n++; }
        foreach (AdminFile::onlyTrashed()->where('trash_root', true)->where('deleted_at', '<', $cut)->get() as $f) { $this->purgeFile($f); $n++; }

        return $n;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function kind(string $kind): string
    {
        return $kind === 'categories' ? 'category' : 'department';
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function actor(Request $request): string
    {
        $u = $request->user();
        $name = $u?->name ?? trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? ''));

        return $name ?: 'Admin';
    }

    private function log(Request $request, string $action, string $type, ?int $id, string $name): void
    {
        FmActivity::create([
            'actor_id' => optional($request->user())->id,
            'actor_name' => $this->actor($request),
            'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'entity_name' => $name,
        ]);
    }

    private function meta($page): array
    {
        return ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function storageLimit(): int
    {
        return (int) env('FILE_MANAGER_STORAGE_LIMIT_GB', 250) * 1024 ** 3;
    }

    private function deleteIcon(FmTaxonomy $t): void
    {
        if ($t->icon_path) Storage::disk('public')->delete($t->icon_path);
    }

    private function assertUniqueFolderName(string $name, ?int $parentId, ?int $ignoreId = null): void
    {
        $exists = AdminFileFolder::query()
            ->where(fn ($q) => $parentId ? $q->where('parent_id', $parentId) : $q->whereNull('parent_id'))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'A folder with this name already exists here.']);
        }
    }

    /** id => [files, bytes, subfolders] including everything nested below each folder. */
    private function folderStats(): array
    {
        $folders = AdminFileFolder::query()->get(['id', 'parent_id']);
        $own = AdminFile::query()->whereNotNull('folder_id')
            ->select('folder_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(size),0) as b'))->groupBy('folder_id')->get()->keyBy('folder_id');
        $children = $folders->groupBy('parent_id');

        $stats = [];
        $walk = function (int $id) use (&$walk, &$stats, $own, $children) {
            $files = (int) ($own[$id]->c ?? 0);
            $bytes = (int) ($own[$id]->b ?? 0);
            foreach ($children[$id] ?? [] as $child) {
                $s = $walk($child->id);
                $files += $s['files'];
                $bytes += $s['bytes'];
            }

            return $stats[$id] = ['files' => $files, 'bytes' => $bytes, 'subfolders' => count($children[$id] ?? [])];
        };
        foreach ($folders as $f) {
            if (! isset($stats[$f->id])) $walk($f->id);
        }

        return $stats;
    }

    /** id => [name, parent_id, department] for path building. */
    private function folderNameMap(bool $withTrashed = false): array
    {
        $q = $withTrashed ? AdminFileFolder::withTrashed() : AdminFileFolder::query();

        return $q->get(['id', 'name', 'parent_id'])->keyBy('id')->map(fn ($f) => ['name' => $f->name, 'parent_id' => $f->parent_id])->all();
    }

    /** "/Parent/Child" (the folder's ancestors for a folder; the folder itself for a file location). */
    private function pathFor($folder, array $names, bool $includeSelf = false): string
    {
        $id = is_int($folder) ? $folder : $folder->parent_id;
        $parts = [];
        $guard = 0;
        while ($id && isset($names[$id]) && $guard++ < 20) {
            array_unshift($parts, $names[$id]['name']);
            $id = $names[$id]['parent_id'];
        }
        if (is_int($folder) && ! $includeSelf) array_pop($parts);

        return '/' . implode('/', $parts);
    }

    private function folderPayload(AdminFileFolder $f, array $stats, array $names): array
    {
        $s = $stats[$f->id] ?? ['files' => 0, 'bytes' => 0, 'subfolders' => 0];

        return [
            'id' => $f->id,
            'name' => $f->name,
            'parent_id' => $f->parent_id,
            'department_id' => $f->department_id,
            'tone' => $f->tone ?: 'pink',
            'image_url' => $f->image_path ? '/storage/' . ltrim($f->image_path, '/') : null,
            'is_protected' => (bool) $f->is_protected,
            'is_active' => (bool) $f->is_active,
            'files' => $s['files'],
            'bytes' => $s['bytes'],
            'subfolders' => $s['subfolders'],
            'path' => $this->pathFor($f, $names),
            'created_at' => optional($f->created_at)->toIso8601String(),
        ];
    }

    private function taxonomyUsage(string $kind): array
    {
        $col = $kind === 'category' ? 'category_id' : 'department_id';
        $files = AdminFile::query()->whereNotNull($col)
            ->select($col . ' as k', DB::raw('count(*) as c'), DB::raw('coalesce(sum(size),0) as b'))->groupBy($col)->get()->keyBy('k');
        $folders = $kind === 'department'
            ? AdminFileFolder::query()->whereNotNull('department_id')->select('department_id as k', DB::raw('count(*) as c'))->groupBy('department_id')->get()->keyBy('k')
            : collect();

        $out = [];
        foreach ($files->keys()->merge($folders->keys())->unique() as $id) {
            $out[$id] = ['files' => (int) ($files[$id]->c ?? 0), 'bytes' => (int) ($files[$id]->b ?? 0), 'folders' => (int) ($folders[$id]->c ?? 0)];
        }

        return $out;
    }

    private function taxonomyPayload(FmTaxonomy $t, ?array $usage = null): array
    {
        $usage ??= $this->taxonomyUsage($t->kind);
        $u = $usage[$t->id] ?? ['files' => 0, 'bytes' => 0, 'folders' => 0];

        return [
            'id' => $t->id, 'kind' => $t->kind, 'name' => $t->name, 'tone' => $t->tone,
            'icon_key' => $t->icon_key, 'icon_url' => $t->icon_path ? '/storage/' . ltrim($t->icon_path, '/') : null,
            'is_active' => (bool) $t->is_active,
            'files' => $u['files'], 'bytes' => $u['bytes'], 'folders' => $u['folders'],
        ];
    }

    private function filePayload(AdminFile $f, array $names): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'description' => $f->description,
            'size' => (int) $f->size,
            'mime_type' => $f->mime_type,
            'extension' => strtoupper((string) $f->extension),
            'folder_id' => $f->folder_id !== null ? (int) $f->folder_id : null,
            'folder' => $f->folder?->name,
            'folder_path' => $f->folder_id ? $this->pathFor((int) $f->folder_id, $names, true) : '/',
            'department_id' => $f->department_id,
            'department' => $f->department?->name,
            'category_id' => $f->category_id,
            'category' => $f->category?->name,
            'is_protected' => (bool) $f->is_protected,
            'is_active' => (bool) $f->is_active,
            'uploaded_by' => $f->uploaded_by_name ?: 'Admin',
            'created_at' => optional($f->created_at)->toIso8601String(),
            'updated_at' => optional($f->updated_at)->toIso8601String(),
        ];
    }

    /** Returns a 423 response when the folder (or the nearest protected ancestor) needs a password the caller lacks. */
    private function checkFolderAccess(?AdminFileFolder $folder, ?string $password): ?JsonResponse
    {
        $guard = 0;
        while ($folder && $guard++ < 20) {
            if ($folder->is_protected) {
                if (! $password || ! Hash::check($password, (string) $folder->password_hash)) {
                    return response()->json([
                        'success' => false, 'locked' => true, 'folder_id' => $folder->id,
                        'message' => $password ? 'Incorrect folder password.' : 'This folder is password protected.',
                    ], 423);
                }

                return null;
            }
            $folder = $folder->parent_id ? AdminFileFolder::find($folder->parent_id) : null;
        }

        return null;
    }

    private function checkFileAccess(AdminFile $file, ?string $password): ?JsonResponse
    {
        if ($file->is_protected && (! $password || ! Hash::check($password, (string) $file->password_hash))) {
            // A caller may also present the password of the protected folder holding the file.
            $viaFolder = $file->folder_id && $password && $this->checkFolderAccess(AdminFileFolder::find($file->folder_id), $password) === null
                && $this->nearestProtectedFolder($file->folder_id) !== null;
            if (! $viaFolder) {
                return response()->json(['success' => false, 'locked' => true, 'message' => $password ? 'Incorrect file password.' : 'This file is password protected.'], 423);
            }

            return null;
        }
        if ($file->folder_id) return $this->checkFolderAccess(AdminFileFolder::find($file->folder_id), $password);

        return null;
    }

    private function nearestProtectedFolder(?int $id): ?AdminFileFolder
    {
        $guard = 0;
        while ($id && $guard++ < 20) {
            $f = AdminFileFolder::find($id);
            if (! $f) return null;
            if ($f->is_protected) return $f;
            $id = $f->parent_id;
        }

        return null;
    }

    /** The folder id plus every non-trashed descendant id. */
    private function descendantIds(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier) {
            $frontier = AdminFileFolder::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function trashCounts(): array
    {
        return [
            'folders' => AdminFileFolder::onlyTrashed()->where('trash_root', true)->whereNull('parent_id')->count(),
            'subfolders' => AdminFileFolder::onlyTrashed()->where('trash_root', true)->whereNotNull('parent_id')->count(),
            'files' => AdminFile::onlyTrashed()->where('trash_root', true)->count(),
        ];
    }

    private function restoreFolderBatch(AdminFileFolder $root): void
    {
        $batch = $root->trash_batch;
        DB::transaction(function () use ($root, $batch) {
            $parentOk = $root->parent_id && AdminFileFolder::whereKey($root->parent_id)->exists();
            AdminFileFolder::onlyTrashed()->where('trash_batch', $batch)->update(['deleted_at' => null, 'trash_batch' => null, 'trash_root' => false]);
            AdminFile::onlyTrashed()->where('trash_batch', $batch)->update(['deleted_at' => null, 'trash_batch' => null, 'trash_root' => false]);
            if (! $parentOk && $root->parent_id) AdminFileFolder::whereKey($root->id)->update(['parent_id' => null]);
        });
    }

    private function purgeFile(AdminFile $f): void
    {
        Storage::disk($f->disk)->delete($f->path);
        $f->forceDelete();
    }

    private function purgeFolderBatch(AdminFileFolder $root): void
    {
        $batch = $root->trash_batch;
        DB::transaction(function () use ($batch, $root) {
            foreach (AdminFile::onlyTrashed()->where('trash_batch', $batch)->get() as $f) $this->purgeFile($f);
            foreach (AdminFileFolder::onlyTrashed()->where('trash_batch', $batch)->whereNotNull('image_path')->get() as $fd) {
                Storage::disk('public')->delete($fd->image_path);
            }
            // Files trashed separately before the folder keep their own batch; remove those that lived inside.
            AdminFileFolder::onlyTrashed()->where('trash_batch', $batch)->where('id', '!=', $root->id)->forceDelete();
            AdminFileFolder::onlyTrashed()->whereKey($root->id)->forceDelete();
        });
    }
}
