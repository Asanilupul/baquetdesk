<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily automatic backup for cPanel / server (requires cron: php artisan schedule:run)
Schedule::command('backup:daily')->dailyAt('02:00')->withoutOverlapping();

// Payment reminder when subscription ends within 7 days
Schedule::command('subscriptions:remind')->dailyAt('09:00')->withoutOverlapping();
