<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Phar;
use PharData;
use Throwable;
use ZipArchive;

class BackupService
{
    /**
     * Columns that must never be written into a backup archive.
     *
     * @var array<string, list<string>>
     */
    private const SECRET_COLUMNS = [
        'users' => ['password', 'api_token', 'remember_token'],
        'vendors' => ['password'],
        'companies' => ['public_menu_token'],
    ];

    public function backupDirectory(?string $companyId = null): string
    {
        // Only the server operator (BACKUP_PATH in .env) may choose where backups are written
        $configured = trim((string) config('backup.path', ''));
        $base = '';
        if ($configured !== '' && $this->isSafePath($configured)) {
            if (! is_dir($configured)) {
                @mkdir($configured, 0755, true);
            }
            if (is_dir($configured) && is_writable($configured)) {
                $base = rtrim(str_replace('\\', '/', $configured), '/');
            }
        }

        if ($base === '') {
            $fallback = storage_path('app/backups');
            if (! is_dir($fallback)) {
                @mkdir($fallback, 0755, true);
            }
            if (! is_writable($fallback)) {
                throw new \RuntimeException('Backup folder is not writable: '.$fallback);
            }
            $base = str_replace('\\', '/', $fallback);
        }

        // Company isolation: each tenant writes only under its own folder
        if ($companyId !== null && $companyId !== '') {
            $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $companyId) ?: 'unknown';
            $dir = $base.'/'.$safeId;
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (! is_dir($dir) || ! is_writable($dir)) {
                throw new \RuntimeException('Company backup folder is not writable: '.$dir);
            }

            return $dir;
        }

        return $base;
    }

    public function retentionDays(?string $companyId = null): int
    {
        $fromConfig = (int) config('backup.retention_days', 14);
        $fromDb = (int) ($this->setting('backup_retention_days', $companyId) ?? 0);

        return max(1, $fromDb > 0 ? $fromDb : $fromConfig);
    }

    public function autoEnabled(?string $companyId = null): bool
    {
        $env = filter_var(config('backup.auto_enabled', true), FILTER_VALIDATE_BOOLEAN);
        $row = $this->setting('backup_auto_enabled', $companyId);
        if ($row === null || $row === '') {
            return $env;
        }

        return in_array(strtolower($row), ['1', 'true', 'yes', 'on'], true);
    }

    public function saveCompanySettings(string $companyId, bool $autoEnabled, int $retentionDays): void
    {
        $this->putSetting('backup_auto_enabled', $autoEnabled ? '1' : '0', $companyId);
        $this->putSetting('backup_retention_days', (string) max(1, min(365, $retentionDays)), $companyId);
    }

    /**
     * Company-specific value first, then the legacy global row.
     */
    private function setting(string $key, ?string $companyId = null): ?string
    {
        $companyId = trim((string) $companyId);
        if ($companyId !== '' && $this->settingsHaveCompanyColumn()) {
            $value = DB::table('system_settings')->where('key', $key)->where('company_id', $companyId)->value('value');
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $query = DB::table('system_settings')->where('key', $key);
        if ($this->settingsHaveCompanyColumn()) {
            $query->whereNull('company_id');
        }
        $value = $query->value('value');

        return $value === null ? null : (string) $value;
    }

    private function putSetting(string $key, string $value, ?string $companyId = null): void
    {
        $match = ['key' => $key];
        if ($this->settingsHaveCompanyColumn()) {
            $companyId = trim((string) $companyId);
            $match['company_id'] = $companyId !== '' ? $companyId : null;
        }

        DB::table('system_settings')->updateOrInsert(
            $match,
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function settingsHaveCompanyColumn(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('system_settings', 'company_id');
    }

    /**
     * True when this company already has a successful backup recorded for today (app timezone).
     */
    public function hasSuccessfulBackupToday(?string $companyId = null): bool
    {
        $companyId = trim((string) $companyId);
        if ($companyId === '') {
            return false;
        }

        $suffix = '_'.$companyId;
        $lastRun = (string) ($this->setting('backup_last_run'.$suffix) ?? '');
        $lastError = (string) ($this->setting('backup_last_error'.$suffix) ?? '');
        if ($lastRun !== '' && $lastError === '' && str_starts_with($lastRun, now()->toDateString())) {
            return true;
        }

        // Also accept a backup file stamped with today's date in the company folder
        try {
            $today = now()->format('Y-m-d');
            foreach (File::files($this->backupDirectory($companyId)) as $file) {
                $name = $file->getFilename();
                if (str_starts_with($name, 'banquetdesk_backup_') && str_contains($name, $today)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // ignore folder errors — treat as not backed up
        }

        return false;
    }

    /**
     * Create today's backup for a company when auto backup is on and none exists yet.
     *
     * @return array{ran: bool, skipped?: bool, reason?: string, result?: array}|null
     */
    public function ensureDailyBackup(?string $companyId = null): ?array
    {
        $companyId = trim((string) $companyId);
        if ($companyId === '') {
            return ['ran' => false, 'skipped' => true, 'reason' => 'missing_company'];
        }
        if (! $this->autoEnabled($companyId)) {
            return ['ran' => false, 'skipped' => true, 'reason' => 'auto_disabled'];
        }
        if ($this->hasSuccessfulBackupToday($companyId)) {
            return ['ran' => false, 'skipped' => true, 'reason' => 'already_today'];
        }

        $result = $this->createBackup('daily', $companyId);

        return ['ran' => true, 'result' => $result];
    }

    /**
     * @return array{path: string, filename: string, size: int, tables: int, created_at: string, format: string, company_id: string}
     */
    public function createBackup(string $trigger = 'manual', ?string $companyId = null): array
    {
        $companyId = trim((string) $companyId);
        if ($companyId === '') {
            throw new \InvalidArgumentException('company_id is required for backups.');
        }

        if (! Schema::hasTable('companies') || ! DB::table('companies')->where('id', $companyId)->exists()) {
            throw new \RuntimeException('Company not found for backup.');
        }

        $dir = $this->backupDirectory($companyId);
        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new \RuntimeException('Backup directory not writable: '.$dir);
        }

        $stamp = now()->format('Y-m-d_His');
        $tempDir = storage_path('app/backup-temp/'.$stamp.'_'.uniqid());
        File::ensureDirectoryExists($tempDir);

        try {
            $manifest = [
                'app' => 'BanquetDesk',
                'version' => '2.0',
                'created_at' => now()->toIso8601String(),
                'trigger' => $trigger,
                'php' => PHP_VERSION,
                'database' => config('database.default'),
                'company_id' => $companyId,
                'scope' => 'company',
            ];

            $tablesPayload = [];
            $tableCount = 0;
            foreach ($this->backupTables() as $table) {
                try {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    // Global settings are not part of a company backup
                    if ($table === 'system_settings') {
                        continue;
                    }
                    $rows = $this->withoutSecrets($table, $this->rowsForCompany($table, $companyId));
                    $tablesPayload[$table] = $rows;
                    $tableCount++;
                    File::put($tempDir.'/'.$table.'.json', json_encode($rows, JSON_UNESCAPED_UNICODE));
                } catch (Throwable $e) {
                    $tablesPayload[$table] = ['_error' => $e->getMessage()];
                }
            }

            File::put($tempDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $fullJson = json_encode([
                'manifest' => $manifest,
                'tables' => $tablesPayload,
            ], JSON_UNESCAPED_UNICODE);
            File::put($tempDir.'/full_export.json', $fullJson);

            $archive = $this->buildArchive($dir, $stamp, $trigger, $tempDir, $fullJson, $companyId);
            $size = is_file($archive['path']) ? (int) filesize($archive['path']) : 0;
            $this->rememberLastBackup($archive['filename'], $archive['path'], $size, $trigger, null, $companyId);
            $this->pruneOldBackups($companyId);

            return [
                'path' => $archive['path'],
                'filename' => $archive['filename'],
                'size' => $size,
                'tables' => $tableCount,
                'created_at' => now()->toDateTimeString(),
                'format' => $archive['format'],
                'company_id' => $companyId,
            ];
        } catch (Throwable $e) {
            $this->rememberLastBackup(null, null, 0, $trigger, $e->getMessage(), $companyId);
            throw $e;
        } finally {
            if (is_dir($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * Run a company-scoped backup for every company (scheduled jobs).
     *
     * @return list<array{company_id: string, filename?: string, error?: string}>
     */
    public function createBackupForAllCompanies(string $trigger = 'daily', bool $force = false): array
    {
        if (! Schema::hasTable('companies')) {
            return [];
        }

        $results = [];
        foreach (DB::table('companies')->orderBy('created_at')->get(['id', 'name']) as $company) {
            $companyId = (string) $company->id;
            try {
                if ($trigger === 'daily' && ! $force && ! $this->autoEnabled($companyId)) {
                    $results[] = [
                        'company_id' => $companyId,
                        'name' => (string) ($company->name ?? ''),
                        'filename' => '(skipped — automatic backup disabled)',
                        'size' => 0,
                        'skipped' => true,
                    ];

                    continue;
                }

                // Hourly/daily scheduler: skip companies that already succeeded today
                if ($trigger === 'daily' && $this->hasSuccessfulBackupToday($companyId)) {
                    $results[] = [
                        'company_id' => $companyId,
                        'name' => (string) ($company->name ?? ''),
                        'filename' => '(skipped — already backed up today)',
                        'size' => 0,
                        'skipped' => true,
                    ];

                    continue;
                }

                $created = $this->createBackup($trigger, $companyId);
                $results[] = [
                    'company_id' => $companyId,
                    'name' => (string) ($company->name ?? ''),
                    'filename' => $created['filename'],
                    'size' => $created['size'],
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'company_id' => $companyId,
                    'name' => (string) ($company->name ?? ''),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{path: string, filename: string, format: string}
     */
    private function buildArchive(string $dir, string $stamp, string $trigger, string $tempDir, string $fullJson, string $companyId = ''): array
    {
        $safeCompany = preg_replace('/[^A-Za-z0-9_-]/', '_', $companyId) ?: 'company';

        // 1) Preferred: ZipArchive
        if (class_exists(ZipArchive::class)) {
            $filename = "banquetdesk_backup_{$safeCompany}_{$stamp}_{$trigger}.zip";
            $zipPath = $dir.'/'.$filename;
            $zip = new ZipArchive;
            $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened === true) {
                foreach (File::files($tempDir) as $file) {
                    $zip->addFile($file->getPathname(), $file->getFilename());
                }
                $zip->close();
                if (is_file($zipPath)) {
                    return ['path' => $zipPath, 'filename' => $filename, 'format' => 'zip'];
                }
            }
        }

        // 2) Fallback: tar via PharData
        if (class_exists(PharData::class)) {
            $filename = "banquetdesk_backup_{$safeCompany}_{$stamp}_{$trigger}.tar";
            $tarPath = $dir.'/'.$filename;
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
            if (is_file($tarPath.'.gz')) {
                @unlink($tarPath.'.gz');
            }
            try {
                $phar = new PharData($tarPath);
                foreach (File::files($tempDir) as $file) {
                    $phar->addFile($file->getPathname(), $file->getFilename());
                }
                unset($phar);
                if (function_exists('gzopen') && method_exists(PharData::class, 'compress')) {
                    try {
                        $phar2 = new PharData($tarPath);
                        $phar2->compress(Phar::GZ);
                        unset($phar2);
                        @unlink($tarPath);
                        $gzName = $filename.'.gz';
                        $gzPath = $dir.'/'.$gzName;
                        if (is_file($gzPath)) {
                            return ['path' => $gzPath, 'filename' => $gzName, 'format' => 'tar.gz'];
                        }
                    } catch (Throwable $e) {
                        // keep plain tar
                    }
                }
                if (is_file($tarPath)) {
                    return ['path' => $tarPath, 'filename' => $filename, 'format' => 'tar'];
                }
            } catch (Throwable $e) {
                // continue to JSON fallback
            }
        }

        // 3) Last resort: single JSON / gzip JSON (works without zip extension)
        if (function_exists('gzencode')) {
            $filename = "banquetdesk_backup_{$safeCompany}_{$stamp}_{$trigger}.json.gz";
            $path = $dir.'/'.$filename;
            File::put($path, gzencode($fullJson, 6));
            if (is_file($path)) {
                return ['path' => $path, 'filename' => $filename, 'format' => 'json.gz'];
            }
        }

        $filename = "banquetdesk_backup_{$safeCompany}_{$stamp}_{$trigger}.json";
        $path = $dir.'/'.$filename;
        File::put($path, $fullJson);
        if (! is_file($path)) {
            throw new \RuntimeException('Unable to create backup file. Enable PHP zip extension, or ensure backup folder is writable.');
        }

        return ['path' => $path, 'filename' => $filename, 'format' => 'json'];
    }

    public function pruneOldBackups(?string $companyId = null): void
    {
        if ($companyId === null || $companyId === '') {
            return;
        }

        $dir = $this->backupDirectory($companyId);
        $keepDays = $this->retentionDays($companyId);
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        foreach (File::files($dir) as $file) {
            $name = $file->getFilename();
            if (! str_starts_with($name, 'banquetdesk_backup_')) {
                continue;
            }
            if ($file->getMTime() < $cutoff) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @return list<array{filename: string, size: int, modified_at: string}>
     */
    public function listBackups(int $limit = 20, ?string $companyId = null): array
    {
        if ($companyId === null || $companyId === '') {
            return [];
        }

        $dir = $this->backupDirectory($companyId);
        $files = collect(File::files($dir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'banquetdesk_backup_'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->take($limit)
            ->map(fn ($f) => [
                'filename' => $f->getFilename(),
                'size' => $f->getSize(),
                'modified_at' => date('Y-m-d H:i:s', $f->getMTime()),
            ])
            ->values()
            ->all();

        return $files;
    }

    public function status(?string $companyId = null): array
    {
        $companyId = trim((string) $companyId);
        $settings = [];
        foreach (['run', 'file', 'size', 'error', 'trigger'] as $field) {
            $key = 'backup_last_'.$field.'_'.$companyId;
            $value = $this->setting($key);
            if ($value !== null) {
                $settings[$key] = $value;
            }
        }

        $auto = $this->autoEnabled($companyId);
        $hasToday = $companyId !== '' ? $this->hasSuccessfulBackupToday($companyId) : false;
        $lastTrigger = $settings['backup_last_trigger_'.$companyId] ?? null;

        return [
            'auto_enabled' => $auto,
            'path' => $companyId !== '' ? $this->backupDirectory($companyId) : $this->backupDirectory(),
            'path_managed_by_server' => true,
            'retention_days' => $this->retentionDays($companyId),
            'company_id' => $companyId !== '' ? $companyId : null,
            'last_run' => $settings['backup_last_run_'.$companyId] ?? null,
            'last_file' => $settings['backup_last_file_'.$companyId] ?? null,
            'last_size' => isset($settings['backup_last_size_'.$companyId]) ? (int) $settings['backup_last_size_'.$companyId] : null,
            'last_error' => $settings['backup_last_error_'.$companyId] ?? null,
            'last_trigger' => $lastTrigger,
            'backed_up_today' => $hasToday,
            'needs_daily' => $auto && $companyId !== '' && ! $hasToday,
            'scheduler_hint' => 'Server cron must run: * * * * * php artisan schedule:run',
            'files' => $this->listBackups(10, $companyId !== '' ? $companyId : null),
            'zip_available' => class_exists(ZipArchive::class),
            'restore_formats' => ['.zip', '.tar', '.tar.gz', '.json.gz', '.json'],
            'scope' => 'company',
        ];
    }

    /**
     * Emergency restore: replace THIS company's table data from a BanquetDesk backup.
     *
     * @return array{tables_restored: int, rows_restored: int, safety_backup: ?string, source: string, warnings: list<string>, company_id: string}
     */
    public function restoreFromFile(string $absolutePath, string $originalName, string $companyId): array
    {
        $companyId = trim($companyId);
        if ($companyId === '') {
            throw new \InvalidArgumentException('company_id is required for restore.');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new \RuntimeException('Backup file not found or not readable.');
        }

        $name = $originalName !== '' ? $originalName : basename($absolutePath);
        $payload = $this->extractTablesPayload($absolutePath, $name);
        if ($payload === []) {
            throw new \RuntimeException('No table data found in backup. Use a BanquetDesk backup (.zip / .tar.gz / .json.gz / .json).');
        }

        $manifestCompany = $this->extractManifestCompanyId($absolutePath, $name, $payload);
        if ($manifestCompany !== '' && $manifestCompany !== $companyId) {
            throw new \RuntimeException('This backup belongs to another company. Restore cancelled.');
        }

        $warnings = [];
        $safetyName = null;
        try {
            $safety = $this->createBackup('pre-restore', $companyId);
            $safetyName = $safety['filename'] ?? null;
        } catch (Throwable $e) {
            $warnings[] = 'Could not create pre-restore safety backup: '.$e->getMessage();
        }

        $tablesRestored = 0;
        $rowsRestored = 0;

        $driver = config('database.default');
        $this->disableForeignKeys($driver);

        try {
            DB::transaction(function () use ($payload, $companyId, &$tablesRestored, &$rowsRestored, &$warnings) {
                foreach ($this->backupTables() as $table) {
                    if ($table === 'system_settings') {
                        continue;
                    }
                    if (! isset($payload[$table]) || ! Schema::hasTable($table)) {
                        continue;
                    }
                    $rows = $payload[$table];
                    if (! is_array($rows)) {
                        continue;
                    }
                    if (isset($rows['_error'])) {
                        $warnings[] = "Skipped {$table}: ".$rows['_error'];

                        continue;
                    }

                    $columns = Schema::getColumnListing($table);
                    $columnFlip = array_flip($columns);

                    if ($table === 'companies') {
                        $rows = array_values(array_filter($rows, function ($row) use ($companyId) {
                            return is_array($row) && (string) ($row['id'] ?? '') === $companyId;
                        }));
                        if ($rows === []) {
                            continue;
                        }
                        $row = $rows[0];
                        $clean = [];
                        foreach ($row as $key => $value) {
                            if (isset($columnFlip[$key]) && $key !== 'id') {
                                $clean[$key] = $value;
                            }
                        }
                        if ($clean !== []) {
                            DB::table('companies')->where('id', $companyId)->update($clean);
                            $rowsRestored++;
                            $tablesRestored++;
                        }

                        continue;
                    }

                    if (! Schema::hasColumn($table, 'company_id')) {
                        continue;
                    }

                    // Backups carry no password hashes: keep each account's current one (or lock new ones)
                    $keptPasswords = [];
                    if (isset(self::SECRET_COLUMNS[$table]) && isset($columnFlip['password'])) {
                        $keptPasswords = DB::table($table)->where('company_id', $companyId)->pluck('password', 'id')->all();
                    }

                    DB::table($table)->where('company_id', $companyId)->delete();
                    $rows = array_values(array_filter($rows, function ($row) use ($companyId) {
                        return is_array($row) && (string) ($row['company_id'] ?? '') === $companyId;
                    }));
                    if (isset(self::SECRET_COLUMNS[$table]) && isset($columnFlip['password'])) {
                        $rows = array_map(function (array $row) use ($keptPasswords) {
                            if (empty($row['password'])) {
                                $row['password'] = $keptPasswords[(string) ($row['id'] ?? '')] ?? Hash::make(Str::random(40));
                            }

                            return $row;
                        }, $rows);
                    }

                    $batch = [];
                    foreach ($rows as $row) {
                        $clean = [];
                        foreach ($row as $key => $value) {
                            if (isset($columnFlip[$key])) {
                                $clean[$key] = $value;
                            }
                        }
                        if ($clean === []) {
                            continue;
                        }
                        $clean['company_id'] = $companyId;
                        $batch[] = $clean;
                        if (count($batch) >= 100) {
                            DB::table($table)->insert($batch);
                            $rowsRestored += count($batch);
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        DB::table($table)->insert($batch);
                        $rowsRestored += count($batch);
                    }
                    $tablesRestored++;
                }
            });
        } finally {
            $this->enableForeignKeys($driver);
        }

        $this->putSetting('backup_last_restore_'.$companyId, now()->toDateTimeString());
        $this->putSetting('backup_last_restore_source_'.$companyId, $name);

        return [
            'tables_restored' => $tablesRestored,
            'rows_restored' => $rowsRestored,
            'safety_backup' => $safetyName,
            'source' => $name,
            'warnings' => $warnings,
            'company_id' => $companyId,
        ];
    }

    /**
     * Restore from a backup file already stored in THIS company's backup folder.
     *
     * @return array{tables_restored: int, rows_restored: int, safety_backup: ?string, source: string, warnings: list<string>, company_id: string}
     */
    public function restoreFromServerFilename(string $filename, string $companyId): array
    {
        $companyId = trim($companyId);
        $filename = basename(str_replace(["\0", '\\', '/'], '', $filename));
        if ($companyId === '' || $filename === '' || ! str_starts_with($filename, 'banquetdesk_backup_')) {
            throw new \RuntimeException('Invalid server backup filename.');
        }
        $path = $this->backupDirectory($companyId).'/'.$filename;
        if (! is_file($path)) {
            throw new \RuntimeException('Server backup file not found for your company: '.$filename);
        }

        $realFile = realpath($path);
        $realDir = realpath($this->backupDirectory($companyId));
        if (! $realFile || ! $realDir || ! str_starts_with($realFile, $realDir)) {
            throw new \RuntimeException('Backup file is outside your company folder.');
        }

        return $this->restoreFromFile($path, $filename, $companyId);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function extractTablesPayload(string $path, string $originalName): array
    {
        $lower = strtolower($originalName !== '' ? $originalName : $path);

        if (str_ends_with($lower, '.json.gz')) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new \RuntimeException('Unable to read .json.gz backup.');
            }
            $json = function_exists('gzdecode') ? @gzdecode($raw) : false;
            if ($json === false) {
                throw new \RuntimeException('Unable to decompress .json.gz (zlib/gzdecode missing).');
            }

            return $this->tablesFromJsonString($json);
        }

        if (str_ends_with($lower, '.json')) {
            $json = @file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException('Unable to read .json backup.');
            }

            return $this->tablesFromJsonString($json);
        }

        $extractDir = storage_path('app/backup-temp/restore_'.uniqid());
        File::ensureDirectoryExists($extractDir);

        try {
            if (str_ends_with($lower, '.zip')) {
                $this->extractZipArchive($path, $extractDir);
            } elseif (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
                $this->extractTarGz($path, $extractDir);
            } elseif (str_ends_with($lower, '.tar')) {
                $this->extractTar($path, $extractDir);
            } else {
                throw new \RuntimeException('Unsupported backup format. Use .zip, .tar, .tar.gz, .json.gz, or .json');
            }

            $fullExport = $extractDir.'/full_export.json';
            if (is_file($fullExport)) {
                return $this->tablesFromJsonString((string) file_get_contents($fullExport));
            }

            $tables = [];
            foreach ($this->backupTables() as $table) {
                $file = $extractDir.'/'.$table.'.json';
                if (! is_file($file)) {
                    continue;
                }
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $tables[$table] = $decoded;
                }
            }

            return $tables;
        } finally {
            if (is_dir($extractDir)) {
                File::deleteDirectory($extractDir);
            }
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function tablesFromJsonString(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Backup JSON is invalid.');
        }
        if (isset($decoded['tables']) && is_array($decoded['tables'])) {
            return $decoded['tables'];
        }
        // Already a table map
        $out = [];
        foreach ($this->backupTables() as $table) {
            if (isset($decoded[$table]) && is_array($decoded[$table])) {
                $out[$table] = $decoded[$table];
            }
        }
        if ($out !== []) {
            return $out;
        }

        throw new \RuntimeException('Backup JSON does not contain BanquetDesk table data.');
    }

    private function extractZipArchive(string $path, string $extractDir): void
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            if ($zip->open($path) === true) {
                $zip->extractTo($extractDir);
                $zip->close();

                return;
            }
        }

        // Some hosts can read zip via PharData even without ZipArchive write support
        if (class_exists(PharData::class)) {
            try {
                $phar = new PharData($path);
                $phar->extractTo($extractDir, null, true);

                return;
            } catch (Throwable $e) {
                // fall through
            }
        }

        throw new \RuntimeException('Cannot read .zip on this server (PHP zip extension missing). Re-save backup as .json.gz from Settings, or enable zip in MultiPHP INI.');
    }

    private function extractTarGz(string $path, string $extractDir): void
    {
        if (! class_exists(PharData::class)) {
            throw new \RuntimeException('PharData unavailable; cannot extract .tar.gz');
        }
        // PharData needs .tar path for decompress; copy then decompress
        $tmpTarGz = $extractDir.'/_in.tar.gz';
        @copy($path, $tmpTarGz);
        $phar = new PharData($tmpTarGz);
        $phar->decompress(); // creates .tar beside it
        unset($phar);
        $tarPath = preg_replace('/\.gz$/i', '', $tmpTarGz);
        if (! is_file($tarPath)) {
            throw new \RuntimeException('Failed to decompress .tar.gz backup.');
        }
        $tar = new PharData($tarPath);
        $tar->extractTo($extractDir, null, true);
    }

    private function extractTar(string $path, string $extractDir): void
    {
        if (! class_exists(PharData::class)) {
            throw new \RuntimeException('PharData unavailable; cannot extract .tar');
        }
        $phar = new PharData($path);
        $phar->extractTo($extractDir, null, true);
    }

    private function disableForeignKeys(string $driver): void
    {
        try {
            Schema::disableForeignKeyConstraints();
        } catch (Throwable $e) {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            }
        }
    }

    private function enableForeignKeys(string $driver): void
    {
        try {
            Schema::enableForeignKeyConstraints();
        } catch (Throwable $e) {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function backupTables(): array
    {
        return [
            'companies', 'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
            'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
            'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
            'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
            'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras',
            'kitchen_sheets', 'store_items', 'item_recipes', 'store_transactions', 'vendors',
            'vendor_categories', 'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries', 'journal_vouchers',
            'function_sheets', 'system_settings', 'production_balancing', 'halls', 'function_types', 'meal_types', 'menu_extras',
            'combo_packages', 'payroll_runs', 'payroll_slips',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForCompany(string $table, string $companyId): array
    {
        if ($table === 'companies') {
            return DB::table('companies')->where('id', $companyId)->get()->map(fn ($r) => (array) $r)->all();
        }

        if (! Schema::hasColumn($table, 'company_id')) {
            return [];
        }

        return DB::table($table)->where('company_id', $companyId)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractManifestCompanyId(string $path, string $originalName, array $payload): string
    {
        // Prefer company_id on companies rows inside payload
        if (isset($payload['companies']) && is_array($payload['companies'])) {
            foreach ($payload['companies'] as $row) {
                if (is_array($row) && ! empty($row['id'])) {
                    return (string) $row['id'];
                }
            }
        }

        // Try reading manifest from archive / json
        try {
            $lower = strtolower($originalName !== '' ? $originalName : $path);
            $json = null;
            if (str_ends_with($lower, '.json.gz')) {
                $raw = @file_get_contents($path);
                $json = $raw !== false && function_exists('gzdecode') ? @gzdecode($raw) : false;
            } elseif (str_ends_with($lower, '.json')) {
                $json = @file_get_contents($path);
            }
            if (is_string($json) && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $id = $decoded['manifest']['company_id'] ?? ($decoded['company_id'] ?? '');

                    return trim((string) $id);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

    private function rememberLastBackup(?string $filename, ?string $path, int $size, string $trigger, ?string $error, ?string $companyId = null): void
    {
        $suffix = ($companyId !== null && $companyId !== '') ? '_'.$companyId : '';
        $pairs = [
            'backup_last_run'.$suffix => now()->toDateTimeString(),
            'backup_last_file'.$suffix => $filename ?: '',
            'backup_last_size'.$suffix => (string) $size,
            'backup_last_path'.$suffix => $path ?: '',
            'backup_last_trigger'.$suffix => $trigger,
            'backup_last_error'.$suffix => $error ?: '',
        ];
        foreach ($pairs as $key => $value) {
            $this->putSetting($key, $value);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutSecrets(string $table, array $rows): array
    {
        $secrets = self::SECRET_COLUMNS[$table] ?? [];
        if ($secrets === []) {
            return $rows;
        }

        return array_map(function (array $row) use ($secrets) {
            foreach ($secrets as $column) {
                unset($row[$column]);
            }

            return $row;
        }, $rows);
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\|storage/)#', $path);
    }
}
