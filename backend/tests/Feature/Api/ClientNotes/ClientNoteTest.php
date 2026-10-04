<?php

use App\Enums\RoleName;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->client = Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id, 'name' => 'Nina Note']);
});

it('requires authentication', function () {
    $this->getJson("/api/clients/{$this->client->id}/notes")->assertUnauthorized();
    $this->getJson("/api/clients/{$this->client->id}/timeline")->assertUnauthorized();
});

it('lists notes with pinned first and the author', function () {
    $author = $this->alpha->agent(0);
    $old = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $author->id, 'created_at' => now()->subDays(3)]);
    $pinned = ClientNote::factory()->pinned()->create(['client_id' => $this->client->id, 'user_id' => $author->id, 'created_at' => now()->subDays(9)]);
    $new = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $author->id]);
    ClientNote::factory()->create(); // another client's note

    $this->actingAsUser($author);
    $response = $this->getJson("/api/clients/{$this->client->id}/notes")->assertOk();

    expect($response->json('data.*.id'))->toBe([$pinned->id, $new->id, $old->id])
        ->and($response->json('data.0.author.id'))->toBe($author->id)
        ->and($response->json('data.0'))->toMatchArray(['is_pinned' => true, 'can_edit' => true, 'can_delete' => true]);
});

it('keeps notes of clients out of scope away', function (string $method, string $suffix) {
    $note = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(0)->id]);
    $this->actingAsUser($this->bravo->agent(0));

    $url = "/api/clients/{$this->client->id}".str_replace('{note}', (string) $note->id, $suffix);
    $this->json($method, $url, ['body' => 'Sneaky'])->assertForbidden();
})->with([
    ['GET', '/notes'],
    ['POST', '/notes'],
    ['PATCH', '/notes/{note}'],
    ['DELETE', '/notes/{note}'],
    ['GET', '/timeline'],
]);

it('adds a note as the signed-in user, trimmed, never queued for approval', function (RoleName $role) {
    $user = match ($role) {
        RoleName::SalesExecutive => $this->alpha->agent(0),
        RoleName::TeamLead => $this->alpha->teamLead,
        default => $this->makeUser($role),
    };
    $this->actingAsUser($user);

    $response = $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => '  Call back on Friday  ', 'is_pinned' => true])
        ->assertCreated();

    expect($response->json('data'))->toMatchArray(['body' => 'Call back on Friday', 'is_pinned' => true, 'client_id' => $this->client->id])
        ->and($response->json('data.author.id'))->toBe($user->id);
    $this->assertDatabaseHas('client_notes', ['client_id' => $this->client->id, 'user_id' => $user->id]);
    $this->assertDatabaseCount('approval_requests', 0);
})->with([RoleName::Admin, RoleName::Support, RoleName::TeamLead, RoleName::SalesExecutive]);

it('validates the body', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->postJson("/api/clients/{$this->client->id}/notes", [])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => str_repeat('a', 2000)])->assertCreated();
});

it('lets the author edit and anyone with access pin, but not edit the text', function () {
    $note = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(0)->id, 'body' => 'Original']);

    $this->actingAsUser($this->alpha->agent(0));
    $this->patchJson("/api/clients/{$this->client->id}/notes/{$note->id}", ['body' => 'Edited'])
        ->assertOk()->assertJsonPath('data.body', 'Edited');

    $this->actingAsUser($this->alpha->teamLead);
    $this->patchJson("/api/clients/{$this->client->id}/notes/{$note->id}", ['is_pinned' => true])
        ->assertOk()->assertJsonPath('data.is_pinned', true)->assertJsonPath('data.can_edit', false);
    $this->patchJson("/api/clients/{$this->client->id}/notes/{$note->id}", ['body' => 'Hijack'])->assertForbidden();

    expect($note->refresh()->body)->toBe('Edited')->and($note->is_pinned)->toBeTrue();
});

it('lets the author or a user with clients.update delete, soft deleting the note', function () {
    $note = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(0)->id]);
    $other = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(0)->id]);
    $peer = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(1)->id]);

    // A sales executive (no clients.update) cannot delete someone else's note.
    $this->actingAsUser($this->alpha->agent(0));
    $this->deleteJson("/api/clients/{$this->client->id}/notes/{$peer->id}")->assertForbidden();
    $this->deleteJson("/api/clients/{$this->client->id}/notes/{$note->id}")->assertNoContent();

    // A team lead can.
    $this->actingAsUser($this->alpha->teamLead);
    $this->deleteJson("/api/clients/{$this->client->id}/notes/{$other->id}")->assertNoContent();

    expect(ClientNote::query()->pluck('id')->all())->toBe([$peer->id])
        ->and(ClientNote::withTrashed()->count())->toBe(3);
});

it('does not reach a note through another client', function () {
    $elsewhere = Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id]);
    $note = ClientNote::factory()->create(['client_id' => $elsewhere->id, 'user_id' => $this->alpha->agent(0)->id]);

    $this->actingAsUser($this->alpha->agent(0));
    $this->patchJson("/api/clients/{$this->client->id}/notes/{$note->id}", ['is_pinned' => true])->assertNotFound();
});

it('merges notes and activity of the client, its leads, orders and payments, newest first', function () {
    $agent = $this->alpha->agent(0);
    $lead = Lead::factory()->create(['client_id' => $this->client->id, 'owner_id' => $agent->id]);
    $order = Order::factory()->create(['client_id' => $this->client->id, 'owner_id' => $agent->id]);
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    $unrelated = Lead::factory()->create(['owner_id' => $agent->id]);
    Activity::query()->delete();

    $log = fn (string $type, int $id, string $event, string $at, ?int $causer = null) => Activity::query()->forceCreate([
        'log_name' => 'model', 'description' => $event, 'event' => $event, 'subject_type' => $type, 'subject_id' => $id,
        'causer_type' => $causer ? 'user' : null, 'causer_id' => $causer,
        'attribute_changes' => ['attributes' => ['next_follow_up_on' => '2026-10-10'], 'old' => []],
        'created_at' => $at, 'updated_at' => $at,
    ]);
    $log('client', $this->client->id, 'created', now()->subDays(10)->toDateTimeString());
    $log('lead', $lead->id, 'updated', now()->subDays(5)->toDateTimeString(), $agent->id);
    $log('order', $order->id, 'created', now()->subDays(4)->toDateTimeString());
    $log('payment', $payment->id, 'updated', now()->subDays(2)->toDateTimeString());
    $log('lead', $unrelated->id, 'updated', now()->subDay()->toDateTimeString());
    $note = ClientNote::factory()->create(['client_id' => $this->client->id, 'user_id' => $agent->id, 'body' => 'Wants a rebrand', 'created_at' => now()->subDays(3)]);

    $this->actingAsUser($agent);
    $response = $this->getJson("/api/clients/{$this->client->id}/timeline")->assertOk();

    expect($response->json('data'))->toHaveCount(5)
        ->and($response->json('data.*.kind'))->toBe(['activity', 'note', 'activity', 'activity', 'activity'])
        ->and($response->json('data.1'))->toMatchArray(['id' => 'note-'.$note->id, 'link' => '/clients/'.$this->client->id])
        ->and($response->json('data.1.summary'))->toContain('Wants a rebrand')
        ->and($response->json('data.1.actor.id'))->toBe($agent->id)
        ->and($response->json('data.0.link'))->toBe('/orders/'.$order->id)
        ->and($response->json('data.3.summary'))->toContain('updated: next follow up on')
        ->and($response->json('data.3.actor.name'))->toBe($agent->name)
        ->and($response->json('meta.total'))->toBe(5);
});

it('hides activity of orders and leads the user cannot see', function () {
    $hidden = Order::factory()->create(['client_id' => $this->client->id, 'owner_id' => $this->alpha->agent(1)->id]);
    Activity::query()->delete();
    Activity::query()->forceCreate([
        'log_name' => 'model', 'description' => 'created', 'event' => 'created', 'subject_type' => 'order', 'subject_id' => $hidden->id,
    ]);

    $this->actingAsUser($this->alpha->agent(0));
    expect($this->getJson("/api/clients/{$this->client->id}/timeline")->assertOk()->json('data'))->toBe([]);

    $this->actingAsUser($this->alpha->teamLead);
    expect($this->getJson("/api/clients/{$this->client->id}/timeline")->json('data'))->toHaveCount(1);
});

it('paginates the timeline', function () {
    Activity::query()->delete();
    ClientNote::factory()->count(5)->create(['client_id' => $this->client->id, 'user_id' => $this->alpha->agent(0)->id]);
    $this->actingAsUser($this->alpha->agent(0));

    $first = $this->getJson("/api/clients/{$this->client->id}/timeline?page[size]=2&page[number]=1")->assertOk();
    $third = $this->getJson("/api/clients/{$this->client->id}/timeline?page[size]=2&page[number]=3")->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.total'))->toBe(5)
        ->and($first->json('meta.last_page'))->toBe(3)
        ->and($third->json('data'))->toHaveCount(1);
});
