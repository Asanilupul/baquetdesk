<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Phar;
use PharData;
use Throwable;
use ZipArchive;

class BackupService
{
    public function backupDirectory(?string $companyId = null): string
    {
        $configured = trim((string) config('backup.path', ''));
        if ($configured === '') {
            $configured = (string) (DB::table('system_settings')->where('key', 'backup_path')->value('value') ?? '');
        }
        $configured = trim($configured);
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

    public function retentionDays(): int
    {
        $fromConfig = (int) config('backup.retention_days', 14);
        $fromDb = (int) (DB::table('system_settings')->where('key', 'backup_retention_days')->value('value') ?? 0);

        return max(1, $fromDb > 0 ? $fromDb : $fromConfig);
    }

    public function autoEnabled(): bool
    {
        $env = filter_var(config('backup.auto_enabled', true), FILTER_VALIDATE_BOOLEAN);
        $row = DB::table('system_settings')->where('key', 'backup_auto_enabled')->value('value');
        if ($row === null || $row === '') {
            return $env;
        }

        return in_array(strtolower((string) $row), ['1', 'true', 'yes', 'on'], true);
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
                    $rows = $this->rowsForCompany($table, $companyId);
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
    public function createBackupForAllCompanies(string $trigger = 'daily'): array
    {
        if (! Schema::hasTable('companies')) {
            return [];
        }

        $results = [];
        foreach (DB::table('companies')->orderBy('created_at')->get(['id', 'name']) as $company) {
            try {
                $created = $this->createBackup($trigger, (string) $company->id);
                $results[] = [
                    'company_id' => (string) $company->id,
                    'name' => (string) ($company->name ?? ''),
                    'filename' => $created['filename'],
                    'size' => $created['size'],
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'company_id' => (string) $company->id,
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
            $zip = new ZipArchive();
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
        $keepDays = $this->retentionDays();
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
        $settings = DB::table('system_settings')->whereIn('key', [
            'backup_auto_enabled',
            'backup_path',
            'backup_retention_days',
            'backup_last_run_'.$companyId,
            'backup_last_file_'.$companyId,
            'backup_last_size_'.$companyId,
            'backup_last_error_'.$companyId,
            'backup_last_trigger_'.$companyId,
        ])->pluck('value', 'key');

        return [
            'auto_enabled' => $this->autoEnabled(),
            'path' => $companyId !== '' ? $this->backupDirectory($companyId) : $this->backupDirectory(),
            'retention_days' => $this->retentionDays(),
            'company_id' => $companyId !== '' ? $companyId : null,
            'last_run' => $settings['backup_last_run_'.$companyId] ?? null,
            'last_file' => $settings['backup_last_file_'.$companyId] ?? null,
            'last_size' => isset($settings['backup_last_size_'.$companyId]) ? (int) $settings['backup_last_size_'.$companyId] : null,
            'last_error' => $settings['backup_last_error_'.$companyId] ?? null,
            'last_trigger' => $settings['backup_last_trigger_'.$companyId] ?? null,
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

                    DB::table($table)->where('company_id', $companyId)->delete();
                    $rows = array_values(array_filter($rows, function ($row) use ($companyId) {
                        return is_array($row) && (string) ($row['company_id'] ?? '') === $companyId;
                    }));

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

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'backup_last_restore_'.$companyId],
            ['value' => now()->toDateTimeString(), 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'backup_last_restore_source_'.$companyId],
            ['value' => $name, 'updated_at' => now(), 'created_at' => now()]
        );

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
            $zip = new ZipArchive();
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
            'vendor_categories', 'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries',
            'function_sheets', 'system_settings', 'production_balancing', 'halls', 'menu_extras',
            'combo_packages',
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
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\|storage/)#', $path);
    }
}
