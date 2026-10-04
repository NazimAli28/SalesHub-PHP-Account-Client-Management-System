<?php

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PlatformAccount;
use App\Models\Service;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->lead = Lead::factory()->engaged()->create(['owner_id' => $this->agent->id, 'last_message' => 'Original']);
});

describe('store', function () {
    it('creates a lead directly for a sales executive (201) with services', function () {
        $this->actingAsUser($this->agent);
        $client = Client::factory()->create(['owner_id' => $this->agent->id]);
        $services = Service::factory()->count(2)->create();
        $account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();

        $response = $this->postJson('/api/leads', [
            'client_id' => $client->id,
            'platform_account_id' => $account->id,
            'estimated_value_cents' => 25000,
            'last_message' => 'Sent first message',
            'service_ids' => $services->pluck('id')->all(),
        ])->assertCreated()
            ->assertJsonPath('data.stage.value', 'new')
            ->assertJsonPath('data.owner.id', $this->agent->id)
            ->assertJsonPath('data.estimated_value.formatted', '$250.00')
            ->assertJsonPath('data.contacted_on', today()->toDateString())
            ->assertJsonCount(2, 'data.services');

        $lead = Lead::query()->findOrFail($response->json('data.id'));
        expect($lead->owner_id)->toBe($this->agent->id)
            ->and($lead->stage_changed_at)->not->toBeNull()
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('creates the client inline when given a new discord username', function () {
        $this->actingAsUser($this->agent);

        $response = $this->postJson('/api/leads', [
            'client' => ['discord_username' => 'neonfox_tv', 'email' => 'neonfox@example.com'],
        ])->assertCreated();

        $client = Client::query()->where('discord_username', 'neonfox_tv')->firstOrFail();
        expect($client->owner_id)->toBe($this->agent->id)
            ->and($response->json('data.client_id'))->toBe($client->id);
    });

    it('lets a team lead create a lead owned by a teammate', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $client = Client::factory()->create(['owner_id' => $this->agent->id]);

        $this->postJson('/api/leads', ['client_id' => $client->id, 'owner_id' => $this->alpha->agent(1)->id])
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $this->alpha->agent(1)->id);
    });

    it('validates the payload (422)', function () {
        $this->actingAsUser($this->agent);

        $this->postJson('/api/leads', [])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'client']);

        $client = Client::factory()->create(['owner_id' => $this->agent->id]);
        $this->postJson('/api/leads', ['client_id' => $client->id, 'stage' => 'lost'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lost_reason']);
        $this->postJson('/api/leads', ['client_id' => $client->id, 'stage' => 'won'])
            ->assertUnprocessable()->assertJsonValidationErrors(['stage']);
        $this->postJson('/api/leads', ['client_id' => $client->id, 'currency' => 'usd', 'contacted_on' => today()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency', 'contacted_on']);
        $this->postJson('/api/leads', ['client' => ['discord_username' => $client->discord_username]])
            ->assertUnprocessable()->assertJsonValidationErrors(['client.discord_username']);
    });

    it('rejects referenced records outside the user scope', function () {
        $this->actingAsUser($this->agent);
        $otherTeamClient = Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
        $otherAccount = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();
        $myClient = Client::factory()->create(['owner_id' => $this->agent->id]);

        $this->postJson('/api/leads', ['client_id' => $otherTeamClient->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_id']);
        $this->postJson('/api/leads', ['client_id' => $myClient->id, 'platform_account_id' => $otherAccount->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['platform_account_id']);
        $this->postJson('/api/leads', ['client_id' => $myClient->id, 'owner_id' => $this->alpha->agent(1)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
    });
});

describe('show', function () {
    it('returns a visible lead and 403 for one out of scope, 404 when missing', function () {
        $foreign = Lead::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
        $this->actingAsUser($this->agent);

        $this->getJson("/api/leads/{$this->lead->id}")->assertOk()->assertJsonPath('data.id', $this->lead->id);
        $this->getJson("/api/leads/{$foreign->id}")->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->getJson('/api/leads/999999')->assertNotFound();
    });
});

describe('update', function () {
    it('applies directly for a team lead (200)', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'Client replied', 'stage' => 'quoted'])
            ->assertOk()
            ->assertJsonPath('data.last_message', 'Client replied')
            ->assertJsonPath('data.stage.value', 'quoted');

        expect($this->lead->fresh()->last_message)->toBe('Client replied')
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('applies directly for support with PUT, including services', function () {
        $this->actingAsRole(RoleName::Support);
        $service = Service::factory()->create();

        $this->putJson("/api/leads/{$this->lead->id}", ['service_ids' => [$service->id]])
            ->assertOk()
            ->assertJsonPath('data.services.0.id', $service->id);
    });

    it('queues the change for a sales executive (202) and leaves the lead unchanged', function () {
        $this->actingAsUser($this->agent);
        $service = Service::factory()->create();

        $response = $this->patchJson("/api/leads/{$this->lead->id}", [
            'last_message' => 'Asked for the price',
            'service_ids' => [$service->id],
            'reason' => 'Client confirmed on Discord',
        ])->assertAccepted()
            ->assertJsonPath('data.action.value', 'update')
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.payload.changes', ['last_message' => 'Asked for the price'])
            ->assertJsonPath('data.payload.relations', ['services' => [$service->id]])
            ->assertJsonPath('data.before.last_message', 'Original')
            ->assertJsonPath('data.before.relations.services', [])
            ->assertJsonPath('data.reason', 'Client confirmed on Discord');

        $approval = ApprovalRequest::query()->findOrFail($response->json('data.id'));
        expect($approval->pending_key)->toBe('lead:'.$this->lead->id)
            ->and($approval->before)->toHaveKey('updated_at')
            ->and($this->lead->fresh()->last_message)->toBe('Original')
            ->and($this->lead->services()->count())->toBe(0);

        $this->getJson("/api/leads/{$this->lead->id}")
            ->assertJsonPath('data.pending_change.id', $approval->id)
            ->assertJsonPath('data.pending_change.fields', ['last_message', 'services']);
    });

    it('rejects a second pending change and an empty change (422)', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'Original'])
            ->assertUnprocessable()->assertJsonValidationErrors(['changes']);

        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'First'])->assertAccepted();
        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This record already has a pending change.');

        expect(ApprovalRequest::query()->count())->toBe(1);
    });

    it('prohibits changing the owner through update', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}", ['owner_id' => $this->alpha->agent(1)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
    });

    it('denies updates outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'x'])->assertForbidden();
        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'x'])->assertForbidden();
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->deleteJson("/api/leads/{$this->lead->id}")->assertNoContent();

        expect(Lead::query()->find($this->lead->id))->toBeNull()
            ->and(Lead::withTrashed()->find($this->lead->id))->not->toBeNull();
    });

    it('queues the deletion for a team lead and a sales executive (202)', function (string $who) {
        $user = $who === 'team lead' ? $this->alpha->teamLead : $this->agent;
        $this->actingAsUser($user);

        $this->deleteJson("/api/leads/{$this->lead->id}", ['reason' => 'Duplicate lead'])
            ->assertAccepted()
            ->assertJsonPath('data.action.value', ApprovalAction::Delete->value)
            ->assertJsonPath('data.before.id', $this->lead->id)
            ->assertJsonPath('data.reason', 'Duplicate lead');

        expect($this->lead->fresh())->not->toBeNull()
            ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);
    })->with(['team lead', 'sales executive']);

    it('denies deletion outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->deleteJson("/api/leads/{$this->lead->id}")->assertForbidden();
        expect(ApprovalRequest::query()->count())->toBe(0);
    });
});
