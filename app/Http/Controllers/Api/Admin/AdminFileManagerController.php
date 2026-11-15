<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminFile;
use App\Models\AdminFileFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin document storage (File Manager). Files live on the private "local" disk
 * and are only reachable through the authenticated download endpoint.
 */
class AdminFileManagerController extends Controller
{
    private const FOLDER_COLORS = [
        'bg-pink-50 text-pink-600 border-pink-100',
        'bg-blue-50 text-blue-600 border-blue-100',
        'bg-emerald-50 text-emerald-600 border-emerald-100',
        'bg-amber-50 text-amber-600 border-amber-100',
        'bg-purple-50 text-purple-600 border-purple-100',
        'bg-slate-50 text-slate-700 border-slate-200',
    ];

    private const ALLOWED_EXTENSIONS = [
        'dst', 'pes', 'exp', 'jef', 'vp3', 'hus', 'emb', 'ai', 'eps', 'svg', 'psd',
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        'xlsx', 'xls', 'csv', 'docx', 'doc', 'txt',
        'zip', 'mp4', 'mov',
    ];

    public function index(): JsonResponse
    {
        $folders = AdminFileFolder::query()->withCount('files')->orderBy('name')->get();
        $files = AdminFile::query()->with('folder:id,name')->latest()->get();
        $latest = $files->first()?->created_at;

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $folders->map(fn (AdminFileFolder $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'color' => $f->color,
                    'item_count' => (int) $f->files_count,
                ])->values(),
                'files' => $files->map(fn (AdminFile $f) => $this->filePayload($f))->values(),
                'stats' => [
                    'total_bytes' => (int) $files->sum('size'),
                    'total_files' => $files->count(),
                    'total_folders' => $folders->count(),
                    'last_activity_at' => optional($latest)->toIso8601String(),
                ],
            ],
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120|unique:admin_file_folders,name',
        ]);

        $folder = AdminFileFolder::create([
            'name' => trim($validated['name']),
            'color' => self::FOLDER_COLORS[AdminFileFolder::count() % count(self::FOLDER_COLORS)],
            'created_by' => optional($request->user())->id,
        ]);

        return response()->json(['success' => true, 'data' => $folder], 201);
    }

    public function destroyFolder(AdminFileFolder $folder): JsonResponse
    {
        if ($folder->files()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This folder still has files. Move or delete them first.',
            ], 422);
        }
        $folder->delete();

        return response()->json(['success' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|max:51200', // 50 MB each
            'folder_id' => 'nullable|integer|exists:admin_file_folders,id',
        ]);

        $saved = [];
        foreach ($request->file('files', []) as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                return response()->json([
                    'success' => false,
                    'message' => "Files of type .{$ext} are not allowed.",
                ], 422);
            }
            $path = $upload->storeAs('admin-files/' . now()->format('Y/m'), Str::uuid() . '.' . $ext, 'local');
            $saved[] = AdminFile::create([
                'folder_id' => $request->input('folder_id'),
                'name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'path' => $path,
                'disk' => 'local',
                'size' => $upload->getSize(),
                'mime_type' => $upload->getClientMimeType(),
                'extension' => $ext,
                'uploaded_by' => optional($request->user())->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => collect($saved)->map(fn (AdminFile $f) => $this->filePayload($f->load('folder:id,name')))->values(),
        ], 201);
    }

    public function update(Request $request, AdminFile $file): JsonResponse
    {
        $validated = $request->validate([
            'folder_id' => 'nullable|integer|exists:admin_file_folders,id',
            'name' => 'sometimes|string|max:250',
        ]);
        $file->update($validated);

        return response()->json(['success' => true, 'data' => $this->filePayload($file->fresh('folder'))]);
    }

    public function download(AdminFile $file): StreamedResponse|JsonResponse
    {
        if (! Storage::disk($file->disk)->exists($file->path)) {
            return response()->json(['success' => false, 'message' => 'File not found on storage.'], 404);
        }

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    public function destroy(AdminFile $file): JsonResponse
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        return response()->json(['success' => true]);
    }

    private function filePayload(AdminFile $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'size' => (int) $f->size,
            'mime_type' => $f->mime_type,
            'extension' => strtoupper((string) $f->extension),
            'folder_id' => $f->folder_id !== null ? (int) $f->folder_id : null,
            'folder' => $f->folder?->name,
            'created_at' => optional($f->created_at)->toIso8601String(),
            'updated_at' => optional($f->updated_at)->toIso8601String(),
        ];
    }
}
