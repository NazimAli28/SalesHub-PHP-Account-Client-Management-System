<?php

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Team;
use App\Policies\ApprovalRequestPolicy;

beforeEach(function () {
    $this->alpha = Team::factory()->create();
    $this->bravo = Team::factory()->create();

    $this->agentA = $this->makeStaff(RoleName::SalesExecutive, $this->alpha);
    $this->agentB = $this->makeStaff(RoleName::SalesExecutive, $this->bravo);
    $this->leadA = $this->makeStaff(RoleName::TeamLead, $this->alpha);
    $this->leadA2 = $this->makeStaff(RoleName::TeamLead, $this->alpha);
    $this->support = $this->makeUser(RoleName::Support);
    $this->admin = $this->makeUser(RoleName::Admin);

    $this->policy = new ApprovalRequestPolicy;
});

function requestBy($user, string $type = 'lead'): ApprovalRequest
{
    return ApprovalRequest::factory()->create([
        'approvable_type' => $type,
        'approvable_id' => null,
        'pending_key' => null,
        'requested_by_id' => $user->id,
    ]);
}

it('never lets anyone review their own request, admin and support included', function () {
    foreach ([$this->admin, $this->support, $this->leadA] as $user) {
        expect($this->policy->review($user, requestBy($user)))->toBeFalse();
    }
});

it('lets review-all roles review any other user request', function () {
    foreach (['lead', 'platform_account', 'social_account', 'client'] as $type) {
        $request = requestBy($this->agentB, $type);

        expect($this->support->can('review', $request))->toBeTrue()
            ->and($this->admin->can('review', $request))->toBeTrue();
    }
});

it('lets a team lead review lead, client, order and payment requests from their team only', function () {
    foreach (['lead', 'client', 'order', 'payment'] as $type) {
        expect($this->leadA->can('review', requestBy($this->agentA, $type)))->toBeTrue()
            ->and($this->leadA->can('review', requestBy($this->agentB, $type)))->toBeFalse();
    }
});

it('keeps platform and social account requests away from team leads', function () {
    expect($this->leadA->can('review', requestBy($this->agentA, 'platform_account')))->toBeFalse()
        ->and($this->leadA->can('review', requestBy($this->agentA, 'social_account')))->toBeFalse();
});

it('lets a team lead review a request from a peer lead on the same team but not their own', function () {
    expect($this->leadA->can('review', requestBy($this->leadA2)))->toBeTrue()
        ->and($this->leadA->can('review', requestBy($this->leadA)))->toBeFalse();
});

it('does not let sales executives review anything', function () {
    expect($this->agentA->can('review', requestBy($this->agentB)))->toBeFalse();
});

it('does not let a team lead without a team review', function () {
    $teamless = $this->makeUser(RoleName::TeamLead);

    expect($teamless->can('review', requestBy($this->agentA)))->toBeFalse();
});

it('scopes who can view requests', function () {
    $mine = requestBy($this->agentA);
    $teammates = requestBy($this->leadA);
    $foreign = requestBy($this->agentB);

    expect($this->agentA->can('view', $mine))->toBeTrue()
        ->and($this->agentA->can('view', $teammates))->toBeFalse()
        ->and($this->leadA->can('view', $mine))->toBeTrue()
        ->and($this->leadA->can('view', $foreign))->toBeFalse()
        ->and($this->support->can('view', $foreign))->toBeTrue()
        ->and(ApprovalRequest::query()->visibleTo($this->leadA)->count())->toBe(2);
});

it('lets only the requester cancel while pending', function () {
    $request = requestBy($this->agentA);
    $done = ApprovalRequest::factory()->approved($this->support)->create(['requested_by_id' => $this->agentA->id]);

    expect($this->agentA->can('cancel', $request))->toBeTrue()
        ->and($this->agentB->can('cancel', $request))->toBeFalse()
        ->and($this->support->can('cancel', $request))->toBeFalse()
        ->and($this->agentA->can('cancel', $done))->toBeFalse()
        ->and($done->status)->toBe(ApprovalStatus::Approved)
        ->and($request->action)->toBe(ApprovalAction::Update);
});
