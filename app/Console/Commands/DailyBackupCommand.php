<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class DailyBackupCommand extends Command
{
    protected $signature = 'backup:daily {--force : Run even if auto backup is disabled}';

    protected $description = 'Create a daily BanquetDesk backup per company (skips companies already backed up today)';

    public function handle(BackupService $backups): int
    {
        try {
            $results = $backups->createBackupForAllCompanies('daily', (bool) $this->option('force'));
            $ok = 0;
            $fail = 0;
            $skipped = 0;
            foreach ($results as $row) {
                if (! empty($row['error'])) {
                    $fail++;
                    $this->error(($row['name'] ?? $row['company_id']).': '.$row['error']);
                } elseif (! empty($row['skipped'])) {
                    $skipped++;
                    $this->line(($row['name'] ?? $row['company_id']).': '.$row['filename']);
                } else {
                    $ok++;
                    $this->info(($row['name'] ?? $row['company_id']).': '.$row['filename'].' ('.$row['size'].' bytes)');
                }
            }
            $this->info("Done. Created: {$ok}, Skipped: {$skipped}, Failed: {$fail}");

            return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
