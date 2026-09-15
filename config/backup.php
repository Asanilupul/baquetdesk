<?php

return [
    /*
    | Absolute cPanel/server folder for automatic backups.
    | Example: /home/USERNAME/banquetdesk_backups
    | Leave empty to use storage/app/backups
    */
    'path' => env('BACKUP_PATH', ''),

    /* Keep daily backups for this many days */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /* When false, scheduled backup:daily is skipped unless --force */
    'auto_enabled' => env('BACKUP_AUTO_ENABLED', true),
];
