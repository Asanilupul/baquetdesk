<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use App\Support\CompanyApiSession;
use App\Support\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function status(Request $request, BackupService $backups): JsonResponse
    {
        if ($deny = $this->denyUnlessCompany($request)) {
            return $deny;
        }

        $companyId = $this->companyId($request);

        return response()->json(['data' => $backups->status($companyId), 'error' => null]);
    }

    public function saveSettings(Request $request, BackupService $backups): JsonResponse
    {
        if ($deny = $this->denyUnlessCompany($request)) {
            return $deny;
        }

        $companyId = $this->companyId($request);
        $auto = $request->boolean('auto_enabled', true);
        $path = trim((string) $request->input('path', ''));
        $retention = max(1, min(365, (int) $request->input('retention_days', 14)));

        if ($path !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\|storage/)#', $path)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Backup path must be an absolute server path or storage/app/backups'],
            ], 422);
        }

        // Global path/retention settings (base folder); company files still go in base/{company_id}
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

        return response()->json(['data' => $backups->status($companyId), 'error' => null]);
    }

    public function runNow(Request $request, BackupService $backups): JsonResponse
    {
        if ($deny = $this->denyUnlessCompany($request)) {
            return $deny;
        }

        $companyId = $this->companyId($request);

        try {
            $result = $backups->createBackup('manual-server', $companyId);

            return response()->json(['data' => $result + ['status' => $backups->status($companyId)], 'error' => null]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], 500);
        }
    }

    public function download(Request $request, BackupService $backups): BinaryFileResponse|JsonResponse
    {
        if ($deny = $this->denyUnlessCompany($request)) {
            return $deny;
        }

        $companyId = $this->companyId($request);

        try {
            $result = $backups->createBackup('manual-download', $companyId);
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
     * Requires valid Admin username + password for the SAME company.
     */
    public function restore(Request $request, BackupService $backups): JsonResponse
    {
        if ($deny = $this->denyUnlessCompany($request)) {
            return $deny;
        }

        $companyId = $this->companyId($request);
        $username = trim((string) $request->input('admin_username', ''));
        $password = (string) $request->input('admin_password', '');

        if ($username === '' || $password === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Admin username and password are required to restore.'],
            ], 422);
        }

        $adminQuery = DB::table('users')
            ->where('username', $username)
            ->where('role', 'Admin');

        if (Schema::hasColumn('users', 'company_id')) {
            $adminQuery->where('company_id', $companyId);
        }

        $admin = $adminQuery->first();
        $passwordOk = false;
        if ($admin) {
            $stored = (string) ($admin->password ?? '');
            if (Hash::isHashed($stored)) {
                $passwordOk = Hash::check($password, $stored);
            } elseif (hash_equals($stored, $password)) {
                DB::table('users')->where('id', $admin->id)->update([
                    'password' => Hash::make($password),
                    'updated_at' => now(),
                ]);
                $passwordOk = true;
            }
        }

        if (! $admin || ! $passwordOk) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Invalid Admin credentials for this company. Restore cancelled.'],
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
                $dangerous = ['.php', '.phtml', '.phar', '.htaccess', '.shtml', '.jsp', '.aspx', '.exe', '.sh', '.bat'];
                foreach ($dangerous as $bad) {
                    if (str_contains($nameLower, $bad)) {
                        return response()->json([
                            'data' => null,
                            'error' => ['message' => 'Upload rejected: unsafe filename'],
                        ], 422);
                    }
                }
                $ok = in_array($ext, $allowed, true)
                    || str_ends_with($nameLower, '.tar.gz')
                    || str_ends_with($nameLower, '.json.gz');
                if (! $ok) {
                    return response()->json([
                        'data' => null,
                        'error' => ['message' => 'Allowed: .zip, .tar, .tar.gz, .json.gz, .json'],
                    ], 422);
                }

                $safeName = 'restore_'.uniqid('', true).'.'.$ext;
                if (str_ends_with($nameLower, '.tar.gz')) {
                    $safeName = 'restore_'.uniqid('', true).'.tar.gz';
                } elseif (str_ends_with($nameLower, '.json.gz')) {
                    $safeName = 'restore_'.uniqid('', true).'.json.gz';
                }
                $stored = $file->storeAs('backup-uploads', $safeName, 'local');
                $absolute = storage_path('app/'.$stored);

                try {
                    $result = $backups->restoreFromFile($absolute, $name, $companyId);
                } finally {
                    if (is_file($absolute)) {
                        @unlink($absolute);
                    }
                }

                return response()->json([
                    'data' => $result + ['status' => $backups->status($companyId)],
                    'error' => null,
                ]);
            }

            $serverFile = trim((string) $request->input('server_filename', ''));
            if ($serverFile !== '') {
                $result = $backups->restoreFromServerFilename($serverFile, $companyId);

                return response()->json([
                    'data' => $result + ['status' => $backups->status($companyId)],
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

    private function companyId(Request $request): string
    {
        $session = CompanyApiSession::fromRequest($request);
        if ($session && $session['company_id'] !== '') {
            return $session['company_id'];
        }

        return trim((string) ($request->header('X-Company-Id') ?: $request->input('company_id', '')));
    }

    private function denyUnlessCompany(Request $request): ?JsonResponse
    {
        $session = CompanyApiSession::fromRequest($request);
        if ($session === null) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Authentication required. Please log in again.'],
            ], 401);
        }

        $companyId = $this->companyId($request);
        if ($companyId === '') {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Company session required (X-Company-Id). Log in first.'],
            ], 401);
        }

        if (! Schema::hasTable('companies')) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Companies table missing'],
            ], 500);
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        if (! $company) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'Company not found'],
            ], 404);
        }

        if (! CompanySubscription::isUsable($company)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => CompanySubscription::denyMessage($company)],
            ], 403);
        }

        return null;
    }
}
