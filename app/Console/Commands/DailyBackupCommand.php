<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class DailyBackupCommand extends Command
{
    protected $signature = 'backup:daily {--force : Run even if auto backup is disabled}';

    protected $description = 'Create a daily BanquetDesk backup per company (company-isolated folders)';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') && ! $backups->autoEnabled()) {
            $this->info('Automatic backup is disabled. Use --force to run anyway.');

            return self::SUCCESS;
        }

        try {
            $results = $backups->createBackupForAllCompanies('daily');
            $ok = 0;
            $fail = 0;
            foreach ($results as $row) {
                if (! empty($row['error'])) {
                    $fail++;
                    $this->error(($row['name'] ?? $row['company_id']).': '.$row['error']);
                } else {
                    $ok++;
                    $this->info(($row['name'] ?? $row['company_id']).': '.$row['filename'].' ('.$row['size'].' bytes)');
                }
            }
            $this->info("Done. Success: {$ok}, Failed: {$fail}");

            return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
