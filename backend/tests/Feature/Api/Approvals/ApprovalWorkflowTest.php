<?php

use App\Actions\Approvals\SubmitChangeRequest;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Lead;
use App\Models\PlatformAccount;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalSubmitted;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->support = $this->makeUser(RoleName::Support);
    $this->lead = Lead::factory()->engaged()->create(['owner_id' => $this->agent->id, 'last_message' => 'Original']);
});

/**
 * The sales executive submits an update over HTTP and gets the pending request back.
 */
function submitLeadChange($test, array $changes = ['last_message' => 'Changed']): ApprovalRequest
{
    $test->actingAsUser($test->agent);
    $id = $test->patchJson("/api/leads/{$test->lead->id}", $changes)->assertAccepted()->json('data.id');

    return ApprovalRequest::query()->findOrFail($id);
}

it('approves in one step: applies the change and records applied_at, after, reviewer (item 23)', function () {
    $approval = submitLeadChange($this);
    $this->actingAsUser($this->support);

    $this->postJson("/api/approvals/{$approval->id}/approve", ['comment' => 'Looks right'])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'approved')
        ->assertJsonPath('data.after.last_message', 'Changed')
        ->assertJsonPath('data.reviewer.id', $this->support->id)
        ->assertJsonPath('data.review_comment', 'Looks right');

    $approval->refresh();
    expect($this->lead->fresh()->last_message)->toBe('Changed')
        ->and($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->applied_at)->not->toBeNull()
        ->and($approval->reviewed_at)->not->toBeNull()
        ->and($approval->pending_key)->toBeNull();

    $modelEntry = Activity::query()->where('log_name', 'model')->where('event', 'updated')
        ->where('subject_type', 'lead')->where('subject_id', $this->lead->id)->sole();
    expect($modelEntry->causer_id)->toBe($this->support->id)
        ->and($modelEntry->getProperty('approval_request_id'))->toBe($approval->id)
        ->and(Activity::query()->where('log_name', 'approval')->pluck('event')->all())->toBe(['submitted', 'approved']);
});

it('returns 409 when approving an already decided request (item 23)', function () {
    $approval = submitLeadChange($this);
    $this->actingAsUser($this->support);

    $this->postJson("/api/approvals/{$approval->id}/approve")->assertOk();
    $this->postJson("/api/approvals/{$approval->id}/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'approval_already_decided');
    $this->postJson("/api/approvals/{$approval->id}/reject", ['comment' => 'Too late now'])->assertConflict();
});

it('never lets the requester review their own request (item 23)', function () {
    // A team lead's delete is queued (no leads.delete), so the team lead is the requester here.
    $this->actingAsUser($this->alpha->teamLead);
    $id = $this->deleteJson("/api/leads/{$this->lead->id}")->assertAccepted()->json('data.id');

    $this->postJson("/api/approvals/{$id}/approve")->assertForbidden();
    expect($this->lead->fresh())->not->toBeNull();
});

it('marks a stale request failed and returns 409 (item 24)', function () {
    $approval = submitLeadChange($this, ['last_message' => 'From the request']);

    $this->travel(1)->minutes();
    $this->actingAsUser($this->alpha->teamLead);
    $this->patchJson("/api/leads/{$this->lead->id}", ['estimated_value_cents' => 12345])->assertOk();

    $this->actingAsUser($this->support);
    $this->postJson("/api/approvals/{$approval->id}/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'approval_failed')
        ->assertJsonPath('message', 'Record changed since request was submitted');

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Failed)
        ->and($approval->failure_message)->toBe('Record changed since request was submitted')
        ->and($approval->pending_key)->toBeNull()
        ->and($approval->applied_at)->toBeNull()
        ->and($this->lead->fresh()->last_message)->toBe('Original');

    // The record is free for a new request again.
    $this->actingAsUser($this->agent);
    $this->patchJson("/api/leads/{$this->lead->id}", ['last_message' => 'Retry'])->assertAccepted();
});

it('fails a request whose record was deleted in the meantime', function () {
    $approval = submitLeadChange($this);
    $this->lead->delete();
    $this->actingAsUser($this->support);

    $this->postJson("/api/approvals/{$approval->id}/approve")->assertConflict();
    expect($approval->fresh()->failure_message)->toBe('The record no longer exists.');
});

it('requires a comment to reject and leaves the record unchanged (item 25)', function () {
    $approval = submitLeadChange($this);
    $this->actingAsUser($this->support);

    $this->postJson("/api/approvals/{$approval->id}/reject")->assertUnprocessable()->assertJsonValidationErrors(['comment']);
    $this->postJson("/api/approvals/{$approval->id}/reject", ['comment' => 'no'])->assertUnprocessable();

    $this->postJson("/api/approvals/{$approval->id}/reject", ['comment' => 'Please attach the screenshot'])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'rejected')
        ->assertJsonPath('data.review_comment', 'Please attach the screenshot');

    expect($this->lead->fresh()->last_message)->toBe('Original')
        ->and($approval->fresh()->pending_key)->toBeNull();
});

it('applies an approved deletion as a soft delete', function () {
    $this->actingAsUser($this->agent);
    $id = $this->deleteJson("/api/leads/{$this->lead->id}")->assertAccepted()->json('data.id');

    $this->actingAsUser($this->alpha->teamLead);
    $this->postJson("/api/approvals/{$id}/approve")->assertOk()->assertJsonPath('data.after.id', $this->lead->id);

    expect(Lead::query()->find($this->lead->id))->toBeNull()
        ->and(Lead::withTrashed()->find($this->lead->id)?->deleted_at)->not->toBeNull();
});

it('lets a team lead review team lead requests only (item 26)', function () {
    $own = submitLeadChange($this);

    $foreignLead = Lead::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
    $foreign = app(SubmitChangeRequest::class)->update($this->bravo->agent(0), $foreignLead, ['last_message' => 'x']);

    $account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();
    $accountRequest = app(SubmitChangeRequest::class)->update($this->agent, $account, ['notes' => 'Limited since Monday']);

    $this->actingAsUser($this->alpha->teamLead);
    $this->postJson("/api/approvals/{$foreign->id}/approve")->assertForbidden();
    $this->postJson("/api/approvals/{$accountRequest->id}/approve")->assertForbidden();
    $this->postJson("/api/approvals/{$own->id}/approve")->assertOk();

    // Support decides the platform account request through the generic applier.
    $this->actingAsUser($this->support);
    $this->postJson("/api/approvals/{$accountRequest->id}/approve")->assertOk();
    expect($account->fresh()->notes)->toBe('Limited since Monday');
});

it('queues a request for new platform accounts (item 29)', function () {
    $this->actingAsUser($this->agent);

    $id = $this->postJson('/api/platform-accounts/request-new', ['quantity' => 2, 'note' => 'Two accounts limited this week'])
        ->assertAccepted()
        ->assertJsonPath('data.action.value', ApprovalAction::RequestAccounts->value)
        ->assertJsonPath('data.approvable', null)
        ->assertJsonPath('data.payload', ['workstation_id' => $this->alpha->station(0)->id, 'quantity' => 2, 'note' => 'Two accounts limited this week'])
        ->json('data.id');

    // Several may be pending at once; none blocks a record.
    $this->postJson('/api/platform-accounts/request-new', ['quantity' => 1])->assertAccepted();
    $this->postJson('/api/platform-accounts/request-new', ['quantity' => 1, 'workstation_id' => $this->alpha->station(1)->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['workstation_id']);
    $this->postJson('/api/platform-accounts/request-new', ['quantity' => 0])->assertUnprocessable();

    $this->actingAsUser($this->alpha->teamLead);
    $this->postJson("/api/approvals/{$id}/approve")->assertForbidden();

    $accounts = PlatformAccount::query()->count();
    $this->actingAsUser($this->support);
    $this->postJson("/api/approvals/{$id}/approve")->assertOk()->assertJsonPath('data.status.value', 'approved');
    expect(PlatformAccount::query()->count())->toBe($accounts);
});

it('notifies reviewers on submission and the requester on decision', function () {
    $outsider = $this->bravo->teamLead;
    $approval = submitLeadChange($this);

    expect($this->support->notifications()->where('type', ApprovalSubmitted::class)->count())->toBe(1)
        ->and($this->alpha->teamLead->notifications()->where('type', ApprovalSubmitted::class)->count())->toBe(1)
        ->and($outsider->notifications()->count())->toBe(0)
        ->and($this->agent->notifications()->count())->toBe(0);

    $this->actingAsUser($this->support);
    $this->postJson("/api/approvals/{$approval->id}/approve")->assertOk();

    $notification = $this->agent->notifications()->sole();
    expect($notification->type)->toBe(ApprovalDecided::class)
        ->and($notification->data['status'])->toBe('approved')
        ->and($notification->data['approval_request_id'])->toBe($approval->id);
});

it('lets the requester cancel their own pending request only', function () {
    $approval = submitLeadChange($this);

    $this->actingAsUser($this->alpha->agent(1));
    $this->postJson("/api/approvals/{$approval->id}/cancel")->assertForbidden();

    $this->actingAsUser($this->agent);
    $this->postJson("/api/approvals/{$approval->id}/cancel")->assertOk()->assertJsonPath('data.status.value', 'cancelled');
    $this->postJson("/api/approvals/{$approval->id}/cancel")->assertForbidden();

    expect($approval->fresh()->pending_key)->toBeNull();
});
