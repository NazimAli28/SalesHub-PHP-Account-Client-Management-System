<?php

use App\Models\Import;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:send-reminders')->dailyAt('08:00')->withoutOverlapping();

// Housekeeping: never-started uploads (and their files) after 24 h, old audit-log entries
// (activitylog.clean_after_days, ACTIVITY_LOG_RETENTION_DAYS).
Schedule::command('model:prune', ['--model' => [Import::class]])->dailyAt('03:00');
Schedule::command('activitylog:clean --force')->dailyAt('03:15');

// Public demo: back to the seeded data every hour.
Schedule::command('demo:reset')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('saleshub.demo_mode'));
