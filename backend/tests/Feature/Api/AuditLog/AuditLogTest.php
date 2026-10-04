<?php

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
});

function logEntry(string $log, string $event, ?User $causer = null, $subject = null, array $properties = [], ?string $at = null): Activity
{
    $logger = activity($log)->event($event)->withProperties($properties);
    $causer && $logger->causedBy($causer);
    $subject && $logger->performedOn($subject);

    /** @var Activity $activity */
    $activity = $logger->log("{$log}.{$event} happened");

    if ($at !== null) {
        $activity->forceFill(['created_at' => $at])->save();
    }

    return $activity;
}

it('is admin only', function (RoleName $role) {
    $this->actingAsRole($role);

    $this->getJson('/api/audit-log')->assertForbidden();
})->with([RoleName::Support, RoleName::TeamLead, RoleName::SalesExecutive]);

it('requires authentication', function () {
    $this->getJson('/api/audit-log')->assertUnauthorized();
});

it('lists entries newest first with causer and subject summaries', function () {
    $admin = $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);
    logEntry('user', 'deactivated', $admin, $agent, at: '2026-09-01 10:00:00');
    $latest = logEntry('security', 'credentials_revealed', $agent, null, ['fields' => ['discord_password']], at: '2026-09-02 10:00:00');

    $response = $this->getJson('/api/audit-log?filter[log_name]=user,security')->assertOk();

    expect($response->json('data.0.id'))->toBe($latest->id)
        ->and($response->json('data.1.causer'))->toBe(['id' => $admin->id, 'name' => $admin->name, 'username' => $admin->username])
        ->and($response->json('data.1.subject'))->toBe(['type' => 'user', 'id' => $agent->id, 'label' => $agent->name])
        ->and($response->json('data.0.subject'))->toBeNull()
        ->and($response->json('data.1'))->toHaveKeys(['id', 'log_name', 'event', 'description', 'causer_id', 'properties', 'created_at']);
});

it('filters by causer, subject, log, event and search', function () {
    $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);
    $lead = Lead::factory()->create(['owner_id' => $agent->id]);

    $a = logEntry('auth', 'login', $agent, $agent);
    $b = logEntry('approval', 'approved', $this->alpha->teamLead, $lead);
    $c = logEntry('user', 'deactivated', $this->alpha->teamLead, $agent);

    $ids = fn (string $query) => collect($this->getJson("/api/audit-log?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $only = fn (...$entries) => collect($entries)->pluck('id')->sort()->values()->all();

    expect($ids('filter[causer]='.$agent->id.'&filter[log_name]=auth,approval,user'))->toBe($only($a))
        ->and($ids('filter[subject_type]=lead&filter[subject_id]='.$lead->id.'&filter[log_name]=approval'))->toBe($only($b))
        ->and($ids('filter[subject_type]=user&filter[subject_id]='.$agent->id.'&filter[log_name]=auth,approval,user'))->toBe($only($a, $c))
        ->and($ids('filter[log_name]=approval'))->toBe($only($b))
        ->and($ids('filter[event]=deactivated'))->toBe($only($c))
        ->and($ids('filter[event]=login,approved'))->toBe($only($a, $b))
        ->and($ids('filter[search]=approval.approved'))->toBe($only($b));
});

it('filters by date range with inclusive bounds', function () {
    $this->actingAsRole(RoleName::Admin);
    $early = logEntry('auth', 'login', at: '2026-08-31 23:59:00');
    $mid = logEntry('auth', 'login', at: '2026-09-15 12:00:00');
    $late = logEntry('auth', 'login', at: '2026-10-01 00:01:00');

    $ids = fn (string $query) => collect($this->getJson("/api/audit-log?filter[log_name]=auth&{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('filter[from]=2026-09-01&filter[to]=2026-09-30'))->toBe([$mid->id])
        ->and($ids('filter[from]=2026-09-15'))->toBe([$late->id, $mid->id])
        ->and($ids('filter[to]=2026-08-31'))->toBe([$early->id]);
    $this->getJson('/api/audit-log?filter[from]=yesterday')->assertUnprocessable();
});

it('sorts and paginates', function () {
    $this->actingAsRole(RoleName::Admin);
    foreach (range(1, 6) as $i) {
        logEntry('custom', "event{$i}");
    }

    $asc = $this->getJson('/api/audit-log?filter[log_name]=custom&sort=created_at,id')->json('data.0.event');
    $desc = $this->getJson('/api/audit-log?filter[log_name]=custom')->json('data.0.event');
    expect($asc)->toBe('event1')->and($desc)->toBe('event6');

    $this->getJson('/api/audit-log?filter[log_name]=custom&page[size]=4&page[number]=2')
        ->assertOk()->assertJsonPath('meta.total', 6)->assertJsonCount(2, 'data');
    $this->getJson('/api/audit-log?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/audit-log?sort=properties')->assertStatus(400);
});

it('is read-only', function () {
    $this->actingAsRole(RoleName::Admin);
    $entry = logEntry('auth', 'login');

    $this->postJson('/api/audit-log', [])->assertStatus(405);
    $this->deleteJson("/api/audit-log/{$entry->id}")->assertStatus(404);
    $this->patchJson('/api/audit-log', [])->assertStatus(405);
});

it('never exposes secret values, even when one was logged', function () {
    $this->actingAsRole(RoleName::Admin);
    $secret = 'S3cr3t-Value-12345';
    logEntry('model', 'updated', null, $this->alpha->agent(0), [
        'attributes' => ['name' => 'Visible', 'discord_password' => $secret, 'recovery_phone' => $secret],
        'old' => ['email_password' => $secret],
        'password' => $secret,
        'api_token' => $secret,
        'nested' => ['deep' => ['remember_token' => $secret, 'note' => 'fine']],
    ]);

    $json = $this->getJson('/api/audit-log?filter[log_name]=model')->assertOk();

    expect($json->getContent())->not->toContain($secret)
        ->and($json->json('data.0.properties.attributes.name'))->toBe('Visible')
        ->and($json->json('data.0.properties.attributes.discord_password'))->toBe('[redacted]')
        ->and($json->json('data.0.properties.nested.deep.note'))->toBe('fine');
});

it('does not leak passwords written by the user management endpoints', function () {
    $admin = $this->actingAsRole(RoleName::Admin);
    $password = 'Str0ng!Passw0rd';

    $created = $this->postJson('/api/users', [
        'name' => 'Audit Subject', 'username' => 'audit.subject', 'email' => 'audit.subject@example.com',
        'password' => $password, 'role' => 'sales_executive',
    ])->assertCreated()->json('data.id');
    $this->patchJson("/api/users/{$created}", ['password' => 'An0ther!Passw0rd', 'name' => 'Audit Renamed'])->assertOk();
    $this->patchJson("/api/users/{$created}/deactivate")->assertOk();

    $content = $this->getJson('/api/audit-log?filter[subject_type]=user&filter[subject_id]='.$created)->assertOk()->getContent();

    expect($content)->not->toContain($password)
        ->and($content)->not->toContain('An0ther!Passw0rd')
        ->and($content)->not->toContain('$2y$')
        ->and($content)->toContain('password_reset')
        ->and($content)->toContain('deactivated');

    $entries = $this->getJson('/api/audit-log?filter[subject_type]=user&filter[subject_id]='.$created.'&filter[event]=role_changed')->json('data');
    expect($entries[0]['causer']['id'])->toBe($admin->id);
});

it('resolves labels for soft-deleted subjects', function () {
    $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(1);
    logEntry('user', 'deleted', null, $agent);
    $agent->delete();

    $this->getJson('/api/audit-log?filter[event]=deleted')
        ->assertOk()
        ->assertJsonPath('data.0.subject.label', $agent->name);
});
