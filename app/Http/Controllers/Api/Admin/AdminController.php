<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use App\Models\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * Get dashboard statistics
     */
    public function dashboard(Request $request): JsonResponse
    {
        $stats = $this->adminService->getDashboardStats($request->user());

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get admin logs
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = $this->adminService->getRecentLogs($request->get('limit', 50));

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    // ============================================================
    // 🔹 NEW: Backup Management
    // ============================================================

    /**
     * Get all backups
     * GET /api/admin/backups
     */
    public function backups(Request $request): JsonResponse
    {
        $backups = Backup::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $backups->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'filename' => $backup->filename,
                    'file_path' => $backup->file_path,
                    'size' => $backup->size,
                    'file_size' => $backup->file_size,
                    'status' => $backup->status,
                    'type' => $backup->type,
                    'notes' => $backup->notes,
                    'metadata' => $backup->metadata,
                    'created_by' => $backup->creator?->name,
                    'created_at' => $backup->created_at?->toISOString(),
                    'completed_at' => $backup->completed_at?->toISOString(),
                    'restored_at' => $backup->restored_at?->toISOString(),
                    'download_url' => $backup->download_url,
                ];
            }),
            'meta' => [
                'current_page' => $backups->currentPage(),
                'total' => $backups->total(),
                'per_page' => $backups->perPage(),
            ],
        ]);
    }

    /**
     * Create a new backup
     * POST /api/admin/backups
     */
    public function createBackup(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|in:manual,scheduled',
            'tables' => 'nullable|string',
            'exclude_tables' => 'nullable|string',
        ]);

        try {
            $adminId = auth()->id();

            // Build command
            $command = 'backup:create';
            $params = [];

            if ($request->input('type')) {
                $params['--type'] = $request->input('type');
            }

            if ($request->input('tables')) {
                $params['--tables'] = $request->input('tables');
            }

            if ($request->input('exclude_tables')) {
                $params['--exclude-tables'] = $request->input('exclude_tables');
            }

            $params['--admin-id'] = $adminId;

            // Build command string
            $commandString = $command;
            foreach ($params as $key => $value) {
                $commandString .= " {$key}={$value}";
            }

            // Run the command in the background
            Artisan::queue($commandString);

            Log::info('Backup initiated by admin', [
                'admin_id' => $adminId,
                'params' => $params,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Backup started successfully. It will be processed in the background.',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to initiate backup', [
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate backup: ' . $e->getMessage(),
            ], 500);
        }
    }

  /**
 * Download backup file
 * GET /api/admin/backups/{backup}/download
 */
public function downloadBackup(Backup $backup)
{
    try {
        if ($backup->status !== Backup::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Backup is not ready for download',
            ], 400);
        }

        $storage = Storage::disk((string) config('uploads.backup_disk', 'local'));

        if (!$storage->exists($backup->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Backup file not found',
            ], 404);
        }

        return $storage->download($backup->file_path, $backup->filename);

    } catch (\Exception $e) {
        Log::error('Failed to download backup', [
            'backup_id' => $backup->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to download backup: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Delete a backup
     * DELETE /api/admin/backups/{backup}
     */
    public function deleteBackup(Backup $backup): JsonResponse
    {
        try {
            // Delete file from storage
            $storage = Storage::disk((string) config('uploads.backup_disk', 'local'));

            if ($storage->exists($backup->file_path)) {
                $storage->delete($backup->file_path);
            }

            $backup->delete();

            Log::info('Backup deleted', [
                'backup_id' => $backup->id,
                'admin_id' => auth()->id(),
                'filename' => $backup->filename,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Backup deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete backup', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete backup: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get backup statistics
     * GET /api/admin/backups/stats
     */
    public function backupStats(): JsonResponse
    {
        $stats = [
            'total' => Backup::count(),
            'completed' => Backup::where('status', Backup::STATUS_COMPLETED)->count(),
            'failed' => Backup::where('status', Backup::STATUS_FAILED)->count(),
            'pending' => Backup::where('status', Backup::STATUS_PENDING)->count(),
            'restored' => Backup::where('status', Backup::STATUS_RESTORED)->count(),
            'manual' => Backup::where('type', Backup::TYPE_MANUAL)->count(),
            'scheduled' => Backup::where('type', Backup::TYPE_SCHEDULED)->count(),
            'total_size' => Backup::sum('size'),
            'latest' => Backup::latest()->first()?->filename,
            'last_backup_at' => Backup::latest()->first()?->created_at?->toISOString(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
