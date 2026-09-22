<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic backups (requires cron every minute: php artisan schedule:run)
// Primary run at 02:00 app timezone, plus hourly catch-up if the 02:00 slot was missed.
Schedule::command('backup:daily')->dailyAt('02:00')->withoutOverlapping(30);
Schedule::command('backup:daily')->hourly()->withoutOverlapping(50);

// Payment reminder when subscription ends within 7 days
Schedule::command('subscriptions:remind')->dailyAt('09:00')->withoutOverlapping();
