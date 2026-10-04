<?php

use App\Enums\RoleName;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();
    $this->teammate = PlatformAccount::factory()->assigned($this->alpha->station(1))->create();
    $this->other = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();
    $this->free = PlatformAccount::factory()->create();
});

function accountIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index to the own workstation for a sales executive', function () {
    $this->actingAsUser($this->alpha->agent(0));

    expect(accountIds($this->getJson('/api/platform-accounts')->assertOk()))->toBe([$this->mine->id]);
});

it('scopes the index to the team for a team lead', function () {
    $this->actingAsUser($this->alpha->teamLead);

    expect(accountIds($this->getJson('/api/platform-accounts')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);
});

it('shows every account, unassigned included, to support and admin', function (RoleName $role) {
    $this->actingAsRole($role);

    expect(accountIds($this->getJson('/api/platform-accounts')->assertOk()))
        ->toBe([$this->mine->id, $this->teammate->id, $this->other->id, $this->free->id]);
})->with([RoleName::Support, RoleName::Admin]);

it('filters by standing, workstation, team, assignment, batch range and search', function () {
    $this->actingAsRole(RoleName::Support);

    $limited = PlatformAccount::factory()->assigned($this->bravo->station(0))->create([
        'standing' => 'limited',
        'batch_date' => '2025-03-10',
        'email' => 'needle.account@example.com',
        'discord_username' => 'haystack_user',
    ]);

    expect(accountIds($this->getJson('/api/platform-accounts?filter[standing]=limited')))->toBe([$limited->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[workstation]='.$this->bravo->station(0)->id)))
        ->toBe([$this->other->id, $limited->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[team]='.$this->alpha->team->id)))
        ->toBe([$this->mine->id, $this->teammate->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[assigned]=0')))->toBe([$this->free->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[assigned]=1')))->not->toContain($this->free->id)
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[batch_from]=2025-03-01&filter[batch_to]=2025-03-31')))->toBe([$limited->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[search]=needle')))->toBe([$limited->id])
        ->and(accountIds($this->getJson('/api/platform-accounts?filter[search]=haystack')))->toBe([$limited->id]);

    $this->getJson('/api/platform-accounts?filter[batch_from]=nope')->assertUnprocessable();
});

it('sorts, paginates and rejects unknown filters, sorts and includes (400)', function () {
    $this->actingAsRole(RoleName::Support);
    PlatformAccount::factory()->count(6)->create();

    $asc = $this->getJson('/api/platform-accounts?sort=email&page[size]=100')->json('data.*.email');
    $desc = $this->getJson('/api/platform-accounts?sort=-email&page[size]=100')->json('data.*.email');
    expect($asc)->toBe(collect($asc)->sort()->values()->all())
        ->and($desc)->toBe(array_reverse($asc));

    $this->getJson('/api/platform-accounts?page[size]=3&page[number]=2')
        ->assertOk()->assertJsonPath('meta.per_page', 3)->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 10);

    $this->getJson('/api/platform-accounts?filter[email_password]=x')->assertStatus(400);
    $this->getJson('/api/platform-accounts?sort=email_password')->assertStatus(400);
    $this->getJson('/api/platform-accounts?include=leads')->assertStatus(400);
});

it('embeds the workstation with its team and the social account count, and formats values', function () {
    SocialAccount::factory()->count(2)->create(['platform_account_id' => $this->mine->id]);
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/platform-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.workstation.code', $this->alpha->station(0)->code)
        ->assertJsonPath('data.0.workstation.team.id', $this->alpha->team->id)
        ->assertJsonPath('data.0.social_accounts_count', 2)
        ->assertJsonPath('data.0.standing.value', 'active')
        ->assertJsonPath('data.0.standing.label', 'Active')
        ->assertJsonPath('data.0.batch_date', $this->mine->batch_date->format('Y-m-d'))
        ->assertJsonPath('data.0.pending_change', null);

    $this->getJson('/api/platform-accounts?include=socialAccounts')->assertJsonCount(2, 'data.0.social_accounts');
});

it('never exposes credentials in list, show or include responses', function () {
    $account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create([
        'email_password' => 'Sup3rSecretMail!', 'discord_password' => 'Sup3rSecretDisc!',
        'recovery_phone' => '+15550001111', 'phone_holder_name' => 'Hidden Holder',
    ]);
    SocialAccount::factory()->create(['platform_account_id' => $account->id, 'password' => 'Sup3rSecretSocial!']);
    $this->actingAsRole(RoleName::Admin);

    foreach (['/api/platform-accounts?include=socialAccounts', "/api/platform-accounts/{$account->id}?include=socialAccounts"] as $url) {
        $body = $this->getJson($url)->assertOk()->getContent();
        expect($body)->not->toContain('Sup3rSecret')->not->toContain('+15550001111')->not->toContain('Hidden Holder')
            ->and($body)->not->toContain('"email_password"')->not->toContain('"discord_password"')->not->toContain('"password"');
    }

    $this->getJson("/api/platform-accounts/{$account->id}")
        ->assertJsonPath('data.has_email_password', true)
        ->assertJsonPath('data.has_discord_password', true)
        ->assertJsonPath('data.has_recovery_phone', true);
});

it('shows a visible account, 403 out of scope and 404 when missing', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson("/api/platform-accounts/{$this->mine->id}")->assertOk()->assertJsonPath('data.id', $this->mine->id);
    $this->getJson("/api/platform-accounts/{$this->teammate->id}")->assertForbidden();
    $this->getJson("/api/platform-accounts/{$this->free->id}")->assertForbidden();
    $this->getJson('/api/platform-accounts/999999')->assertNotFound();
});
