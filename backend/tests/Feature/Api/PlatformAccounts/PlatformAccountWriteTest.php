<?php

use App\Enums\AccountStanding;
use App\Enums\ApprovalStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\PlatformAccount;
use App\Models\Workstation;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create(['notes' => 'Original']);
});

function validAccountPayload(array $overrides = []): array
{
    return [
        'email' => 'fresh.account@example.com',
        'email_password' => 'Sup3rSecretMail!',
        'discord_username' => 'fresh_account',
        'discord_password' => 'Sup3rSecretDisc!',
        'recovery_phone' => '+15550002222',
        'phone_holder_name' => 'Fictional Holder',
        'batch_date' => '2026-09-01',
        ...$overrides,
    ];
}

describe('store', function () {
    it('creates an unassigned account for support with encrypted write-only credentials (201)', function () {
        $this->actingAsRole(RoleName::Support);

        $response = $this->postJson('/api/platform-accounts', validAccountPayload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'fresh.account@example.com')
            ->assertJsonPath('data.standing.value', 'active')
            ->assertJsonPath('data.workstation_id', null)
            ->assertJsonPath('data.has_email_password', true)
            ->assertJsonPath('data.social_accounts_count', 0);

        expect($response->getContent())->not->toContain('Sup3rSecret')->not->toContain('+15550002222');

        $account = PlatformAccount::query()->findOrFail($response->json('data.id'));
        expect($account->email_password)->toBe('Sup3rSecretMail!')
            ->and($account->getRawOriginal('email_password'))->not->toBe('Sup3rSecretMail!')
            ->and($account->recovery_phone)->toBe('+15550002222');
    });

    it('sets assigned_at when created with a workstation', function () {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/platform-accounts', validAccountPayload(['workstation_id' => $this->alpha->station(0)->id]))
            ->assertCreated()
            ->assertJsonPath('data.workstation.team.id', $this->alpha->team->id);

        expect($response->json('data.assigned_at'))->not->toBeNull();
    });

    it('is forbidden for team leads and sales executives', function () {
        foreach ([$this->alpha->teamLead, $this->agent] as $user) {
            $this->actingAsUser($user);
            $this->postJson('/api/platform-accounts', validAccountPayload())->assertForbidden();
        }
    });

    it('validates the payload (422)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->postJson('/api/platform-accounts', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'email_password', 'discord_password', 'batch_date']);
        $this->postJson('/api/platform-accounts', validAccountPayload(['email' => $this->account->email]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson('/api/platform-accounts', validAccountPayload(['standing' => 'bogus', 'batch_date' => '01/09/2026', 'workstation_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['standing', 'batch_date', 'workstation_id']);

        $inactive = Workstation::factory()->create(['is_active' => false]);
        $this->postJson('/api/platform-accounts', validAccountPayload(['workstation_id' => $inactive->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['workstation_id']);
    });
});

describe('update', function () {
    it('applies directly for support (200), credentials included', function () {
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'Warmed up', 'email_password' => 'N3wSecretMail!'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Warmed up');

        $fresh = $this->account->fresh();
        expect($fresh->notes)->toBe('Warmed up')->and($fresh->email_password)->toBe('N3wSecretMail!')
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('queues the change for a sales executive and a team lead (202) and leaves the account unchanged', function (string $who) {
        $this->actingAsUser($who === 'team lead' ? $this->alpha->teamLead : $this->agent);

        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'Needs a new bio', 'reason' => 'Asked by client'])
            ->assertAccepted()
            ->assertJsonPath('data.action.value', 'update')
            ->assertJsonPath('data.payload.changes', ['notes' => 'Needs a new bio'])
            ->assertJsonPath('data.before.notes', 'Original')
            ->assertJsonPath('data.reason', 'Asked by client');

        expect($this->account->fresh()->notes)->toBe('Original');

        $this->getJson("/api/platform-accounts/{$this->account->id}")
            ->assertJsonPath('data.pending_change.fields', ['notes']);
    })->with(['team lead', 'sales executive']);

    it('rejects a second pending change and an empty change (422)', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'Original'])
            ->assertUnprocessable()->assertJsonValidationErrors(['changes']);
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'First'])->assertAccepted();
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'Second'])
            ->assertUnprocessable()->assertJsonPath('message', 'This record already has a pending change.');
    });

    it('prohibits credentials in a queued change and standing/workstation in any update', function () {
        $this->actingAsUser($this->agent);
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['email_password' => 'x', 'discord_password' => 'y'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email_password', 'discord_password']);

        $this->actingAsRole(RoleName::Support);
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['standing' => 'spam', 'workstation_id' => $this->bravo->station(0)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['standing', 'workstation_id']);
    });

    it('validates uniqueness ignoring the record itself', function () {
        $this->actingAsRole(RoleName::Support);
        $other = PlatformAccount::factory()->create();

        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['email' => $this->account->email])->assertOk();
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    });

    it('denies updates outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->teamLead);
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'x'])->assertForbidden();

        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'x'])->assertForbidden();
    });

    it('applies an approved queued update', function () {
        $this->actingAsUser($this->agent);
        $id = $this->patchJson("/api/platform-accounts/{$this->account->id}", ['notes' => 'Approved note'])->assertAccepted()->json('data.id');

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk()->assertJsonPath('data.status.value', 'approved');

        expect($this->account->fresh()->notes)->toBe('Approved note');
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->deleteJson("/api/platform-accounts/{$this->account->id}")->assertNoContent();

        expect(PlatformAccount::query()->find($this->account->id))->toBeNull()
            ->and(PlatformAccount::withTrashed()->find($this->account->id))->not->toBeNull();
    });

    it('queues the deletion for a team lead and a sales executive (202), applied on approval', function (string $who) {
        $this->actingAsUser($who === 'team lead' ? $this->alpha->teamLead : $this->agent);

        $id = $this->deleteJson("/api/platform-accounts/{$this->account->id}", ['reason' => 'Banned'])
            ->assertAccepted()->assertJsonPath('data.action.value', 'delete')->json('data.id');
        expect($this->account->fresh())->not->toBeNull();

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();
        expect(PlatformAccount::query()->find($this->account->id))->toBeNull();
    })->with(['team lead', 'sales executive']);

    it('denies deletion outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->deleteJson("/api/platform-accounts/{$this->account->id}")->assertForbidden();
        expect(ApprovalRequest::query()->count())->toBe(0);
    });
});

describe('assign', function () {
    it('assigns, reassigns and unassigns with assigned_at (support)', function () {
        $free = PlatformAccount::factory()->create();
        $this->actingAsRole(RoleName::Support);

        $response = $this->patchJson("/api/platform-accounts/{$free->id}/assign", ['workstation_id' => $this->bravo->station(0)->id])
            ->assertOk()
            ->assertJsonPath('data.workstation_id', $this->bravo->station(0)->id)
            ->assertJsonPath('data.workstation.team.id', $this->bravo->team->id);
        expect($response->json('data.assigned_at'))->not->toBeNull();

        $this->patchJson("/api/platform-accounts/{$free->id}/assign", ['workstation_id' => null])
            ->assertOk()->assertJsonPath('data.workstation_id', null)->assertJsonPath('data.assigned_at', null);
    });

    it('is forbidden for team leads and sales executives', function () {
        foreach ([$this->alpha->teamLead, $this->agent] as $user) {
            $this->actingAsUser($user);
            $this->patchJson("/api/platform-accounts/{$this->account->id}/assign", ['workstation_id' => null])->assertForbidden();
        }
        expect($this->account->fresh()->workstation_id)->toBe($this->alpha->station(0)->id);
    });

    it('validates the workstation (422)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/platform-accounts/{$this->account->id}/assign", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['workstation_id']);
        $this->patchJson("/api/platform-accounts/{$this->account->id}/assign", ['workstation_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['workstation_id']);
    });
});

describe('standing', function () {
    it('changes the standing directly for support and stamps standing_changed_at', function () {
        $this->actingAsRole(RoleName::Support);

        $response = $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'limited'])
            ->assertOk()->assertJsonPath('data.standing.value', 'limited');

        expect($response->json('data.standing_changed_at'))->not->toBeNull()
            ->and($this->account->fresh()->standing)->toBe(AccountStanding::Limited)
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('queues a standing change for a sales executive (202), then support approves and it is applied', function () {
        $this->actingAsUser($this->agent);

        $id = $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'spam', 'reason' => 'Got flagged'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes', ['standing' => 'spam'])
            ->assertJsonPath('data.before.standing', 'active')
            ->json('data.id');

        $account = $this->account->fresh();
        expect($account->standing)->toBe(AccountStanding::Active)->and($account->standing_changed_at)->toBeNull();

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();

        $account = $this->account->fresh();
        expect($account->standing)->toBe(AccountStanding::Spam)
            ->and($account->standing_changed_at)->not->toBeNull()
            ->and(ApprovalRequest::query()->findOrFail($id)->status)->toBe(ApprovalStatus::Approved);
    });

    it('queues for a team lead too, and rejects a no-op or invalid standing (422)', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors(['changes']);
        $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'bogus'])
            ->assertUnprocessable()->assertJsonValidationErrors(['standing']);
        $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'violation'])->assertAccepted();
    });

    it('denies standing changes outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));
        $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'spam'])->assertForbidden();

        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/platform-accounts/{$this->account->id}/standing", ['standing' => 'spam'])->assertForbidden();
    });
});
