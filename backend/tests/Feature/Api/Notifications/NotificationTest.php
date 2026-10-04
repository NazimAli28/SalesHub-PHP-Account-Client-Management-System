<?php

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalSubmitted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->agent = $this->alpha->agent(0);
});

function giveNotification(User $user, bool $read = false, string $type = ApprovalDecided::class, array $data = []): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'data' => ['message' => 'Hello', ...$data],
        'read_at' => $read ? now() : null,
    ]);
}

it('requires authentication', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
    $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/notifications/read-all')->assertUnauthorized();
});

it('lists only the own notifications, newest first, with the stored data shape', function (RoleName $role) {
    $user = $this->actingAsRole($role);
    $old = giveNotification($user, data: ['approval_request_id' => 5, 'status' => 'approved']);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $new = giveNotification($user, type: ApprovalSubmitted::class);
    giveNotification($this->agent);

    $response = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data');

    expect($response->json('data.*.id'))->toBe([$new->id, $old->id])
        ->and($response->json('data.0.type'))->toBe('ApprovalSubmitted')
        ->and($response->json('data.1.data.approval_request_id'))->toBe(5)
        ->and($response->json('data.1'))->toHaveKeys(['id', 'type', 'data', 'is_read', 'read_at', 'created_at']);
})->with(RoleName::cases());

it('filters unread and read notifications', function () {
    $this->actingAsUser($this->agent);
    $unread = giveNotification($this->agent);
    $read = giveNotification($this->agent, read: true);

    expect($this->getJson('/api/notifications?filter[unread]=true')->json('data.*.id'))->toBe([$unread->id])
        ->and($this->getJson('/api/notifications?filter[unread]=false')->json('data.*.id'))->toBe([$read->id])
        ->and($this->getJson('/api/notifications')->json('data'))->toHaveCount(2);
    $this->getJson('/api/notifications?filter[bogus]=1')->assertStatus(400);
});

it('paginates', function () {
    $this->actingAsUser($this->agent);
    foreach (range(1, 7) as $_) {
        giveNotification($this->agent);
    }

    $this->getJson('/api/notifications?page[size]=3&page[number]=3')
        ->assertOk()
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.current_page', 3)
        ->assertJsonCount(1, 'data');
});

it('counts own unread notifications', function () {
    $this->actingAsUser($this->agent);
    giveNotification($this->agent);
    giveNotification($this->agent);
    giveNotification($this->agent, read: true);
    giveNotification($this->alpha->teamLead);

    $this->getJson('/api/notifications/unread-count')->assertOk()->assertExactJson(['data' => ['unread' => 2]]);
});

it('marks one notification as read', function () {
    $this->actingAsUser($this->agent);
    $notification = giveNotification($this->agent);

    $this->patchJson("/api/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id)
        ->assertJsonPath('data.is_read', true);

    expect($notification->refresh()->read_at)->not->toBeNull();
    $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 0);
    // Marking again keeps it read.
    $this->patchJson("/api/notifications/{$notification->id}/read")->assertOk();
});

it('does not let anyone read another user\'s notification', function () {
    $this->actingAsUser($this->agent);
    $foreign = giveNotification($this->alpha->teamLead);

    $this->patchJson("/api/notifications/{$foreign->id}/read")->assertForbidden();
    $this->patchJson('/api/notifications/'.Str::uuid().'/read')->assertNotFound();

    expect($foreign->refresh()->read_at)->toBeNull();
});

it('marks all own notifications as read without touching others', function () {
    $this->actingAsUser($this->agent);
    giveNotification($this->agent);
    giveNotification($this->agent);
    $foreign = giveNotification($this->alpha->teamLead);

    $this->postJson('/api/notifications/read-all')->assertOk()->assertExactJson(['data' => ['updated' => 2]]);

    expect($this->agent->unreadNotifications()->count())->toBe(0)
        ->and($foreign->refresh()->read_at)->toBeNull();
    $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
});

it('delivers the approval workflow notifications through the API', function () {
    $support = $this->makeUser(RoleName::Support);
    $lead = Lead::factory()->engaged()->create(['owner_id' => $this->agent->id, 'last_message' => 'Original']);

    $this->actingAsUser($this->agent);
    $approvalId = $this->patchJson("/api/leads/{$lead->id}", ['last_message' => 'Changed'])->assertAccepted()->json('data.id');

    $this->actingAsUser($support);
    $submitted = $this->getJson('/api/notifications?filter[unread]=true')->assertOk()->json('data.0');
    expect($submitted['type'])->toBe('ApprovalSubmitted')
        ->and($submitted['data']['approval_request_id'])->toBe($approvalId);

    $this->postJson("/api/approvals/{$approvalId}/approve", [])->assertOk();

    $this->actingAsUser($this->agent);
    $decided = $this->getJson('/api/notifications')->assertOk()->json('data.0');
    expect($decided['type'])->toBe('ApprovalDecided')
        ->and($decided['data']['status'])->toBe('approved')
        ->and($decided['is_read'])->toBeFalse();
});
