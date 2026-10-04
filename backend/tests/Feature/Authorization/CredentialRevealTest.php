<?php

use App\Enums\RoleName;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\Workstation;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->stationA = Workstation::factory()->create(['team_id' => $this->team->id]);
    $this->stationB = Workstation::factory()->create(['team_id' => $this->team->id]);

    $this->agent = $this->makeStaff(RoleName::SalesExecutive, $this->team, $this->stationA);
    $this->lead = $this->makeStaff(RoleName::TeamLead, $this->team);
    $this->support = $this->makeUser(RoleName::Support);

    $this->own = PlatformAccount::factory()->assigned($this->stationA)->create([
        'discord_password' => 'Sup3r-Secret-Discord!', 'email_password' => 'Sup3r-Secret-Mail!',
    ]);
    $this->foreign = PlatformAccount::factory()->assigned($this->stationB)->create();
});

function revealLogs()
{
    return Activity::query()->where('log_name', 'security')->where('event', 'credentials_revealed');
}

it('reveals requested secrets of the own workstation account and logs the fields only', function () {
    $response = $this->spaAs($this->agent)->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", [
        'fields' => ['discord_password', 'email_password'],
    ]);

    $response->assertOk()->assertExactJson(['data' => [
        'discord_password' => 'Sup3r-Secret-Discord!',
        'email_password' => 'Sup3r-Secret-Mail!',
    ]]);

    $log = revealLogs()->sole();

    expect($log->causer_id)->toBe($this->agent->id)
        ->and($log->subject_id)->toBe($this->own->id)
        ->and($log->properties->get('fields'))->toBe(['discord_password', 'email_password'])
        ->and(json_encode($log->toArray()))->not->toContain('Sup3r-Secret');
});

it('refuses a sales executive on another workstation and writes no entry', function () {
    $this->spaAs($this->agent)->spa('POST', "/api/platform-accounts/{$this->foreign->id}/reveal", [
        'fields' => ['discord_password'],
    ])->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');

    expect(revealLogs()->count())->toBe(0);
});

it('refuses a team lead, who has no reveal permission', function () {
    $this->spaAs($this->lead)->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", [
        'fields' => ['discord_password'],
    ])->assertForbidden();

    expect(revealLogs()->count())->toBe(0);
});

it('lets support reveal any account', function () {
    $this->spaAs($this->support)->spa('POST', "/api/platform-accounts/{$this->foreign->id}/reveal", [
        'fields' => ['recovery_phone'],
    ])->assertOk()->assertJsonPath('data.recovery_phone', $this->foreign->recovery_phone);

    expect(revealLogs()->count())->toBe(1);
});

it('validates the requested fields', function (array $body) {
    $this->spaAs($this->agent)->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", $body)
        ->assertStatus(422);

    expect(revealLogs()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['fields' => []]],
    'not a secret column' => [['fields' => ['email']]],
    'duplicate' => [['fields' => ['discord_password', 'discord_password']]],
]);

it('requires authentication and returns 404 for unknown accounts', function () {
    $this->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", ['fields' => ['discord_password']])
        ->assertUnauthorized();

    $this->spaAs($this->support)->spa('POST', '/api/platform-accounts/999999/reveal', ['fields' => ['discord_password']])
        ->assertNotFound();
});

it('reveals social account passwords with the same rules', function () {
    $mine = SocialAccount::factory()->create(['platform_account_id' => $this->own->id, 'password' => 'S0cial-Secret!']);
    $theirs = SocialAccount::factory()->create(['platform_account_id' => $this->foreign->id]);

    $this->spaAs($this->agent)->spa('POST', "/api/social-accounts/{$mine->id}/reveal", ['fields' => ['password']])
        ->assertOk()->assertExactJson(['data' => ['password' => 'S0cial-Secret!']]);

    $log = revealLogs()->sole();
    expect($log->subject_type)->toBe('social_account')
        ->and(json_encode($log->toArray()))->not->toContain('S0cial-Secret!');

    $this->spaAs($this->agent)->spa('POST', "/api/social-accounts/{$theirs->id}/reveal", ['fields' => ['password']])
        ->assertForbidden();

    $this->spaAs($this->lead)->spa('POST', "/api/social-accounts/{$mine->id}/reveal", ['fields' => ['password']])
        ->assertForbidden();

    $this->spaAs($this->agent)->spa('POST', "/api/social-accounts/{$mine->id}/reveal", ['fields' => ['login_email']])
        ->assertStatus(422);

    expect(revealLogs()->count())->toBe(1);
});

it('never serialises encrypted fields in model JSON', function () {
    $social = SocialAccount::factory()->create(['platform_account_id' => $this->own->id]);

    $accountJson = $this->own->fresh()->toArray();
    $socialJson = $social->fresh()->toArray();

    foreach (['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'] as $field) {
        expect($accountJson)->not->toHaveKey($field);
    }
    expect($socialJson)->not->toHaveKey('password')
        ->and(json_encode($accountJson))->not->toContain('Sup3r-Secret');
});

it('throttles reveal requests to 20 per minute', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->spaAs($this->support)->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", ['fields' => ['discord_password']])
            ->assertOk();
    }

    $this->spaAs($this->support)->spa('POST', "/api/platform-accounts/{$this->own->id}/reveal", ['fields' => ['discord_password']])
        ->assertStatus(429);
});
