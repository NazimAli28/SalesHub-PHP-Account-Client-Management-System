<?php

use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->platformAccount = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();
    $this->social = SocialAccount::factory()->create([
        'platform_account_id' => $this->platformAccount->id, 'platform' => 'instagram',
        'username' => 'original_handle', 'is_in_use' => false,
    ]);
});

function validSocialPayload(int $platformAccountId, array $overrides = []): array
{
    return [
        'platform_account_id' => $platformAccountId,
        'platform' => 'behance',
        'username' => 'fresh_portfolio',
        'login_email' => 'fresh.portfolio@example.com',
        'password' => 'Sup3rSecretSocial!',
        'created_on' => '2026-08-01',
        ...$overrides,
    ];
}

describe('store', function () {
    it('creates directly for a sales executive on an own account (201) with an encrypted password', function () {
        $this->actingAsUser($this->agent);

        $response = $this->postJson('/api/social-accounts', validSocialPayload($this->platformAccount->id))
            ->assertCreated()
            ->assertJsonPath('data.platform.value', 'behance')
            ->assertJsonPath('data.is_in_use', false)
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.platform_account.id', $this->platformAccount->id);

        expect($response->getContent())->not->toContain('Sup3rSecret');

        $account = SocialAccount::query()->findOrFail($response->json('data.id'));
        expect($account->password)->toBe('Sup3rSecretSocial!')
            ->and($account->getRawOriginal('password'))->not->toBe('Sup3rSecretSocial!')
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('lets support create on any platform account, but not team leads', function () {
        $foreign = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();

        $this->actingAsRole(RoleName::Support);
        $this->postJson('/api/social-accounts', validSocialPayload($foreign->id))->assertCreated();

        $this->actingAsUser($this->alpha->teamLead);
        $this->postJson('/api/social-accounts', validSocialPayload($this->platformAccount->id, ['username' => 'tl_try']))->assertForbidden();
    });

    it('validates the payload and scope (422)', function () {
        $this->actingAsUser($this->agent);
        $foreign = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();

        $this->postJson('/api/social-accounts', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['platform_account_id', 'platform', 'username', 'password']);
        $this->postJson('/api/social-accounts', validSocialPayload($foreign->id))
            ->assertUnprocessable()->assertJsonValidationErrors(['platform_account_id']);
        $this->postJson('/api/social-accounts', validSocialPayload($this->platformAccount->id, ['platform' => 'myspace', 'login_email' => 'nope', 'is_in_use' => 'maybe']))
            ->assertUnprocessable()->assertJsonValidationErrors(['platform', 'login_email', 'is_in_use']);
        $this->postJson('/api/social-accounts', validSocialPayload($this->platformAccount->id, ['platform' => 'instagram', 'username' => 'original_handle']))
            ->assertUnprocessable()->assertJsonValidationErrors(['username']);
    });
});

describe('update', function () {
    it('applies directly for support (200), password included', function () {
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => true, 'password' => 'N3wSecretSocial!'])
            ->assertOk()->assertJsonPath('data.is_in_use', true);

        expect($this->social->fresh()->password)->toBe('N3wSecretSocial!');
    });

    it('queues the change for a sales executive (202), then applies it on approval', function () {
        $this->actingAsUser($this->agent);

        $id = $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => true, 'reason' => 'Posting today'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes', ['is_in_use' => true])
            ->assertJsonPath('data.reason', 'Posting today')
            ->json('data.id');

        expect($this->social->fresh()->is_in_use)->toBeFalse();
        $this->getJson("/api/social-accounts/{$this->social->id}")->assertJsonPath('data.pending_change.fields', ['is_in_use']);

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();
        expect($this->social->fresh()->is_in_use)->toBeTrue();
    });

    it('rejects a queued password, a second pending change and a no-op (422)', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/social-accounts/{$this->social->id}", ['password' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['changes']);
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => true])->assertAccepted();
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['login_email' => 'second@example.com'])
            ->assertUnprocessable()->assertJsonPath('message', 'This record already has a pending change.');
    });

    it('keeps (platform, username) unique on update', function () {
        $this->actingAsRole(RoleName::Support);
        SocialAccount::factory()->create(['platform' => 'instagram', 'username' => 'taken_handle']);

        $this->patchJson("/api/social-accounts/{$this->social->id}", ['username' => 'taken_handle'])
            ->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['username' => 'original_handle'])->assertOk();
    });

    it('denies team leads (no permission) and out-of-scope users (403)', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => true])->assertForbidden();

        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/social-accounts/{$this->social->id}", ['is_in_use' => true])->assertForbidden();
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204)', function () {
        $this->actingAsRole(RoleName::Admin);

        $this->deleteJson("/api/social-accounts/{$this->social->id}")->assertNoContent();

        expect(SocialAccount::query()->find($this->social->id))->toBeNull()
            ->and(SocialAccount::withTrashed()->find($this->social->id))->not->toBeNull();
    });

    it('queues the deletion for a sales executive (202), applied on approval', function () {
        $this->actingAsUser($this->agent);

        $id = $this->deleteJson("/api/social-accounts/{$this->social->id}", ['reason' => 'Banned'])
            ->assertAccepted()->assertJsonPath('data.action.value', 'delete')->json('data.id');
        expect($this->social->fresh())->not->toBeNull();

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();
        expect(SocialAccount::query()->find($this->social->id))->toBeNull();
    });

    it('denies team leads and out-of-scope users (403)', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->deleteJson("/api/social-accounts/{$this->social->id}")->assertForbidden();

        $this->actingAsUser($this->bravo->agent(0));
        $this->deleteJson("/api/social-accounts/{$this->social->id}")->assertForbidden();
        expect(ApprovalRequest::query()->count())->toBe(0);
    });
});
