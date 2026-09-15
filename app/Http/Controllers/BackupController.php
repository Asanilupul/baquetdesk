<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function status(BackupService $backups): JsonResponse
    {
        return response()->json(['data' => $backups->status(), 'error' => null]);
    }

    public function saveSettings(Request $request, BackupService $backups): JsonResponse
    {
        $auto = $request->boolean('auto_enabled', true);
        $path = trim((string) $request->input('path', ''));
        $retention = max(1, min(365, (int) $request->input('retention_days', 14)));

        if ($path !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\|storage/)#', $path)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Backup path must be an absolute cPanel/server path (e.g. /home/USER/backups) or storage/app/backups'],
            ], 422);
        }

        $pairs = [
            'backup_auto_enabled' => $auto ? '1' : '0',
            'backup_path' => $path,
            'backup_retention_days' => (string) $retention,
        ];
        foreach ($pairs as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['data' => $backups->status(), 'error' => null]);
    }

    public function runNow(BackupService $backups): JsonResponse
    {
        try {
            $result = $backups->createBackup('manual-server');

            return response()->json(['data' => $result + ['status' => $backups->status()], 'error' => null]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    public function download(BackupService $backups): BinaryFileResponse|JsonResponse
    {
        try {
            $result = $backups->createBackup('manual-download');
            $filename = $result['filename'];
            $mime = 'application/octet-stream';
            if (str_ends_with(strtolower($filename), '.zip')) {
                $mime = 'application/zip';
            } elseif (str_ends_with(strtolower($filename), '.json')) {
                $mime = 'application/json';
            } elseif (str_ends_with(strtolower($filename), '.gz')) {
                $mime = 'application/gzip';
            }

            return response()->download($result['path'], $filename, [
                'Content-Type' => $mime,
            ])->deleteFileAfterSend(false);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Emergency restore from uploaded backup OR a server backup filename.
     * Requires valid Admin username + password.
     */
    public function restore(Request $request, BackupService $backups): JsonResponse
    {
        $username = trim((string) $request->input('admin_username', ''));
        $password = (string) $request->input('admin_password', '');

        if ($username === '' || $password === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Admin username and password are required to restore.'],
            ], 422);
        }

        $admin = DB::table('users')
            ->where('username', $username)
            ->where('password', $password)
            ->where('role', 'Admin')
            ->first();

        if (! $admin) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid Admin password. Restore cancelled.'],
            ], 403);
        }

        try {
            @set_time_limit(300);

            if ($request->hasFile('backup')) {
                $file = $request->file('backup');
                if (! $file || ! $file->isValid()) {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Invalid upload. Max size may be limited by PHP upload_max_filesize.'],
                    ], 422);
                }
                $ext = strtolower($file->getClientOriginalExtension());
                $allowed = ['zip', 'tar', 'gz', 'json', 'tgz'];
                $name = $file->getClientOriginalName();
                $nameLower = strtolower($name);
                $ok = in_array($ext, $allowed, true)
                    || str_ends_with($nameLower, '.tar.gz')
                    || str_ends_with($nameLower, '.json.gz');
                if (! $ok) {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Allowed: .zip, .tar, .tar.gz, .json.gz, .json'],
                    ], 422);
                }

                $stored = $file->storeAs('backup-uploads', 'restore_'.uniqid().'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name), 'local');
                $absolute = storage_path('app/'.$stored);

                try {
                    $result = $backups->restoreFromFile($absolute, $name);
                } finally {
                    if (is_file($absolute)) {
                        @unlink($absolute);
                    }
                }

                return response()->json([
                    'data' => $result + ['status' => $backups->status()],
                    'error' => null,
                ]);
            }

            $serverFile = trim((string) $request->input('server_filename', ''));
            if ($serverFile !== '') {
                $result = $backups->restoreFromServerFilename($serverFile);

                return response()->json([
                    'data' => $result + ['status' => $backups->status()],
                    'error' => null,
                ]);
            }

            return response()->json([
                'data' => null,
                'error' => ['message' => 'Upload a backup file or choose a server backup filename.'],
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }
}
