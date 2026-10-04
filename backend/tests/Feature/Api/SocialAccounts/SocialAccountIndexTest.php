<?php

use App\Enums\RoleName;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->pMine = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();
    $this->pTeammate = PlatformAccount::factory()->assigned($this->alpha->station(1))->create();
    $this->pOther = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();
    $this->pFree = PlatformAccount::factory()->create();

    $this->mine = SocialAccount::factory()->create(['platform_account_id' => $this->pMine->id]);
    $this->teammate = SocialAccount::factory()->create(['platform_account_id' => $this->pTeammate->id]);
    $this->other = SocialAccount::factory()->create(['platform_account_id' => $this->pOther->id]);
    $this->free = SocialAccount::factory()->create(['platform_account_id' => $this->pFree->id]);
});

function socialIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index through the parent platform account', function () {
    $this->actingAsUser($this->alpha->agent(0));
    expect(socialIds($this->getJson('/api/social-accounts')->assertOk()))->toBe([$this->mine->id]);

    $this->actingAsUser($this->alpha->teamLead);
    expect(socialIds($this->getJson('/api/social-accounts')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);
});

it('shows every social account to support and admin', function (RoleName $role) {
    $this->actingAsRole($role);

    expect(socialIds($this->getJson('/api/social-accounts')->assertOk()))
        ->toBe([$this->mine->id, $this->teammate->id, $this->other->id, $this->free->id]);
})->with([RoleName::Support, RoleName::Admin]);

it('filters by platform, in_use, platform account and search', function () {
    $this->actingAsRole(RoleName::Support);
    SocialAccount::query()->update(['platform' => 'instagram', 'is_in_use' => false]);
    // refresh(): the mass update above bypassed this instance, so its stale attributes would
    // make Eloquent skip saving values it believes are unchanged.
    $this->other->refresh()->update(['platform' => 'behance', 'is_in_use' => true, 'username' => 'needle_handle']);

    expect(socialIds($this->getJson('/api/social-accounts?filter[platform]=behance')))->toBe([$this->other->id])
        ->and(socialIds($this->getJson('/api/social-accounts?filter[platform]=behance,instagram')))->toHaveCount(4)
        ->and(socialIds($this->getJson('/api/social-accounts?filter[in_use]=1')))->toBe([$this->other->id])
        ->and(socialIds($this->getJson('/api/social-accounts?filter[in_use]=0')))->toHaveCount(3)
        ->and(socialIds($this->getJson('/api/social-accounts?filter[platform_account]='.$this->pMine->id)))->toBe([$this->mine->id])
        ->and(socialIds($this->getJson('/api/social-accounts?filter[search]=needle')))->toBe([$this->other->id])
        ->and(socialIds($this->getJson('/api/social-accounts?filter[search]='.$this->pFree->email)))->toBe([$this->free->id]);
});

it('sorts, paginates and rejects unknown filters, sorts and includes (400)', function () {
    $this->actingAsRole(RoleName::Support);

    $asc = $this->getJson('/api/social-accounts?sort=username')->json('data.*.username');
    $desc = $this->getJson('/api/social-accounts?sort=-username')->json('data.*.username');
    expect($asc)->toBe(collect($asc)->sort()->values()->all())->and($desc)->toBe(array_reverse($asc));

    $this->getJson('/api/social-accounts?page[size]=3&page[number]=2')
        ->assertOk()->assertJsonPath('meta.per_page', 3)->assertJsonPath('meta.total', 4)->assertJsonCount(1, 'data');

    $this->getJson('/api/social-accounts?filter[password]=x')->assertStatus(400);
    $this->getJson('/api/social-accounts?sort=password')->assertStatus(400);
    $this->getJson('/api/social-accounts?include=orders')->assertStatus(400);
});

it('formats values, includes the platform account and never exposes the password', function () {
    $this->mine->update(['password' => 'Sup3rSecretSocial!', 'platform' => 'tiktok']);
    $this->actingAsUser($this->alpha->agent(0));

    $response = $this->getJson('/api/social-accounts?include=platformAccount')
        ->assertOk()
        ->assertJsonPath('data.0.platform.value', 'tiktok')
        ->assertJsonPath('data.0.platform.label', 'TikTok')
        ->assertJsonPath('data.0.has_password', true)
        ->assertJsonPath('data.0.platform_account.id', $this->pMine->id)
        ->assertJsonPath('data.0.pending_change', null);

    expect($response->getContent())->not->toContain('Sup3rSecret')->not->toContain('"password"');
});

it('shows a visible account, 403 out of scope and 404 when missing', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson("/api/social-accounts/{$this->mine->id}")->assertOk()->assertJsonPath('data.id', $this->mine->id);
    $this->getJson("/api/social-accounts/{$this->teammate->id}")->assertForbidden();
    $this->getJson('/api/social-accounts/999999')->assertNotFound();
});
