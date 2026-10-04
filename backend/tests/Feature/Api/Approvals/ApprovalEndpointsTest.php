<?php

use App\Actions\Approvals\SubmitChangeRequest;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Lead;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->support = $this->makeUser(RoleName::Support);

    $submit = app(SubmitChangeRequest::class);
    $leadFor = fn ($user) => Lead::factory()->create(['owner_id' => $user->id, 'last_message' => 'Before']);

    $this->mine = $submit->update($this->alpha->agent(0), $leadFor($this->alpha->agent(0)), ['last_message' => 'After']);
    $this->teammate = $submit->delete($this->alpha->agent(1), $leadFor($this->alpha->agent(1)));
    $this->other = $submit->requestAccounts($this->bravo->agent(0), $this->bravo->station(0)->id, 1);
});

function approvalIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the list per role', function () {
    $this->actingAsUser($this->alpha->agent(0));
    expect(approvalIds($this->getJson('/api/approvals')->assertOk()))->toBe([$this->mine->id]);

    $this->actingAsUser($this->alpha->teamLead);
    expect(approvalIds($this->getJson('/api/approvals')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);

    $this->actingAsUser($this->support);
    expect(approvalIds($this->getJson('/api/approvals')->assertOk()))->toBe([$this->mine->id, $this->teammate->id, $this->other->id]);
});

it('filters by status, action, type, requester, date and reviewability', function () {
    $this->actingAsUser($this->support);
    $this->postJson("/api/approvals/{$this->mine->id}/reject", ['comment' => 'Not this time'])->assertOk();

    $ids = fn (string $query) => approvalIds($this->getJson('/api/approvals?'.$query)->assertOk());

    expect($ids('filter[status]=pending'))->toBe([$this->teammate->id, $this->other->id])
        ->and($ids('filter[status]=rejected'))->toBe([$this->mine->id])
        ->and($ids('filter[action]=delete'))->toBe([$this->teammate->id])
        ->and($ids('filter[type]=lead'))->toBe([$this->mine->id, $this->teammate->id])
        ->and($ids('filter[requester]='.$this->bravo->agent(0)->id))->toBe([$this->other->id])
        ->and($ids('filter[created_from]='.today()->toDateString().'&filter[created_to]='.today()->toDateString()))->toHaveCount(3)
        ->and($ids('filter[created_to]='.today()->subDay()->toDateString()))->toBe([])
        ->and($ids('filter[reviewable]=true'))->toBe([$this->teammate->id, $this->other->id]);

    $this->actingAsUser($this->alpha->teamLead);
    expect($ids('filter[reviewable]=true'))->toBe([$this->teammate->id]);
});

it('shows a request with the field diff and abilities', function () {
    $this->actingAsUser($this->alpha->teamLead);

    $this->getJson("/api/approvals/{$this->mine->id}")
        ->assertOk()
        ->assertJsonPath('data.diff', [['field' => 'last_message', 'before' => 'Before', 'after' => 'After']])
        ->assertJsonPath('data.requester.id', $this->alpha->agent(0)->id)
        ->assertJsonPath('data.can', ['review' => true, 'cancel' => false]);

    $this->getJson("/api/approvals/{$this->other->id}")->assertForbidden();
    $this->getJson('/api/approvals/999999')->assertNotFound();
});

it('reports pending counts for the badge', function () {
    $this->actingAsUser($this->support);
    $this->getJson('/api/approvals/pending-count')->assertOk()->assertExactJson(['data' => ['reviewable' => 3, 'own' => 0]]);

    $this->actingAsUser($this->alpha->teamLead);
    $this->getJson('/api/approvals/pending-count')->assertExactJson(['data' => ['reviewable' => 2, 'own' => 0]]);

    $this->actingAsUser($this->alpha->agent(0));
    $this->getJson('/api/approvals/pending-count')->assertExactJson(['data' => ['reviewable' => 0, 'own' => 1]]);
});

it('stores a pending_key per record and frees it after a decision', function () {
    expect($this->mine->pending_key)->toBe('lead:'.$this->mine->approvable_id)
        ->and($this->other->pending_key)->toBeNull();

    $this->actingAsUser($this->alpha->agent(0));
    $this->postJson("/api/approvals/{$this->mine->id}/cancel")->assertOk();

    expect(ApprovalRequest::query()->whereNotNull('pending_key')->pluck('id')->all())->toBe([$this->teammate->id]);
});
