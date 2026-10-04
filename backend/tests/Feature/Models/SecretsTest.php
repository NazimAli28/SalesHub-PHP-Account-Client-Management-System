<?php

use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('stores platform account credentials encrypted and decrypts them via the model', function () {
    $account = PlatformAccount::factory()->create([
        'email_password' => 'mail-secret-1',
        'discord_password' => 'discord-secret-2',
        'recovery_phone' => '+15550001111',
        'phone_holder_name' => 'Jordan Example',
    ]);

    $raw = DB::table('platform_accounts')->where('id', $account->id)->first();

    foreach (['mail-secret-1' => 'email_password', 'discord-secret-2' => 'discord_password', '+15550001111' => 'recovery_phone', 'Jordan Example' => 'phone_holder_name'] as $plain => $column) {
        expect($raw->{$column})->not->toBe($plain)->and($raw->{$column})->not->toContain($plain);
    }

    $fresh = PlatformAccount::findOrFail($account->id);
    expect($fresh->email_password)->toBe('mail-secret-1')
        ->and($fresh->discord_password)->toBe('discord-secret-2')
        ->and($fresh->recovery_phone)->toBe('+15550001111')
        ->and($fresh->phone_holder_name)->toBe('Jordan Example');
});

it('stores social account passwords encrypted', function () {
    $social = SocialAccount::factory()->create(['password' => 'social-secret']);

    $raw = DB::table('social_accounts')->where('id', $social->id)->value('password');

    expect($raw)->not->toContain('social-secret')
        ->and(SocialAccount::findOrFail($social->id)->password)->toBe('social-secret');
});

it('keeps secrets out of serialized models', function () {
    $account = PlatformAccount::factory()->create();
    $social = SocialAccount::factory()->create();
    $user = User::factory()->create();

    expect(array_keys($account->toArray()))->not->toContain('email_password', 'discord_password', 'recovery_phone', 'phone_holder_name')
        ->and(array_keys($social->toArray()))->not->toContain('password')
        ->and(array_keys($user->toArray()))->not->toContain('password', 'remember_token');
});

it('never writes secrets to the activity log', function () {
    $account = PlatformAccount::factory()->create(['discord_password' => 'discord-secret-2']);
    $account->update(['discord_password' => 'new-secret-3', 'notes' => 'checked']);
    User::factory()->create(['password' => 'Plain@Secret1']);

    $dump = Activity::query()->get()->toJson();

    expect(Activity::query()->count())->toBeGreaterThan(0)
        ->and($dump)->toContain('checked')
        ->and($dump)->not->toContain('secret')
        ->and($dump)->not->toContain('Plain@Secret1')
        ->and($dump)->not->toContain('password');
});
