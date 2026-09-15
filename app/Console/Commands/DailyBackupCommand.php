<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class DailyBackupCommand extends Command
{
    protected $signature = 'backup:daily {--force : Run even if auto backup is disabled}';

    protected $description = 'Create a daily BanquetDesk backup zip on the server (cPanel path or storage/app/backups)';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') && ! $backups->autoEnabled()) {
            $this->info('Automatic backup is disabled. Use --force to run anyway.');

            return self::SUCCESS;
        }

        try {
            $result = $backups->createBackup('daily');
            $this->info('Backup created: '.$result['filename'].' ('.$result['size'].' bytes) in '.$result['path']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
