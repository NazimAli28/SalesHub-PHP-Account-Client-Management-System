<?php

use App\Actions\Demo\ResetDemo;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * The scheduled event whose command contains $needle.
 */
function securityScheduledEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));
}

it('refuses to reset when demo mode is off', function () {
    config(['saleshub.demo_mode' => false]);
    $this->mock(ResetDemo::class)->shouldNotReceive('handle');

    $this->artisan('demo:reset')
        ->expectsOutputToContain('Demo mode is off')
        ->assertFailed();
});

it('resets in demo mode, or anywhere with --force', function () {
    $this->mock(ResetDemo::class)->shouldReceive('handle')->twice();

    config(['saleshub.demo_mode' => true]);
    $this->artisan('demo:reset')->expectsOutputToContain('The demo was reset.')->assertSuccessful();

    config(['saleshub.demo_mode' => false]);
    $this->artisan('demo:reset --force')->assertSuccessful();
});

it('rebuilds the database with the demo data, clears the cache and deletes uploaded imports', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/leftover.csv', "discord_username\nsomeone\n");
    Storage::disk('local')->put('keep.txt', 'not an import');

    Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--seed' => true, '--force' => true])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('cache:clear')->andReturn(0);

    app(ResetDemo::class)->handle();

    Storage::disk('local')->assertMissing('imports/leftover.csv');
    Storage::disk('local')->assertExists('keep.txt');
});

it('schedules the hourly reset only in demo mode, plus daily housekeeping', function () {
    $reset = securityScheduledEvent('demo:reset');

    expect($reset)->not->toBeNull()
        ->and($reset->expression)->toBe('0 * * * *')
        ->and($reset->withoutOverlapping)->toBeTrue();

    config(['saleshub.demo_mode' => false]);
    expect($reset->filtersPass($this->app))->toBeFalse();

    config(['saleshub.demo_mode' => true]);
    expect($reset->filtersPass($this->app))->toBeTrue();

    expect(securityScheduledEvent('model:prune'))->not->toBeNull()
        ->and(securityScheduledEvent('activitylog:clean'))->not->toBeNull()
        ->and(config('activitylog.clean_after_days'))->toBeInt()->toBeGreaterThan(0);
});
