<?php

use App\Actions\Orders\RecalculateOrderTotals;
use App\Enums\ApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->client = Client::factory()->create(['owner_id' => $this->agent->id, 'notes' => 'Original']);
});

describe('store', function () {
    it('creates a client owned by the sales executive (201)', function () {
        $this->actingAsUser($this->agent);

        $this->postJson('/api/clients', [
            'discord_username' => 'neonfox_tv',
            'name' => 'Neon Fox',
            'email' => 'neonfox@example.com',
            'country' => 'GB',
            'nurturing_rating' => 70,
            'expected_upsell_on' => '2026-12-01',
        ])->assertCreated()
            ->assertJsonPath('data.owner_id', $this->agent->id)
            ->assertJsonPath('data.status.value', 'active')
            ->assertJsonPath('data.expected_upsell_on', '2026-12-01');

        expect(ApprovalRequest::query()->count())->toBe(0);
    });

    it('lets a team lead create a client for a teammate', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->postJson('/api/clients', ['discord_username' => 'teamclient', 'owner_id' => $this->alpha->agent(1)->id])
            ->assertCreated()->assertJsonPath('data.owner_id', $this->alpha->agent(1)->id);
    });

    it('validates the payload and out-of-scope owners (422)', function () {
        $this->actingAsUser($this->agent);

        $this->postJson('/api/clients', [])->assertUnprocessable()->assertJsonValidationErrors(['discord_username']);
        $this->postJson('/api/clients', ['discord_username' => $this->client->discord_username])
            ->assertUnprocessable()->assertJsonValidationErrors(['discord_username']);
        $this->postJson('/api/clients', ['discord_username' => 'x1', 'email' => 'nope', 'country' => 'gb', 'nurturing_rating' => 101, 'status' => 'gone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'country', 'nurturing_rating', 'status']);
        $this->postJson('/api/clients', ['discord_username' => 'x2', 'owner_id' => $this->bravo->agent(0)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
    });
});

describe('show (client 360)', function () {
    it('returns leads, orders with balance, upcoming and overdue payments and counts', function () {
        $order = Order::factory()->create(['client_id' => $this->client->id, 'owner_id' => $this->agent->id, 'team_id' => $this->alpha->team->id, 'status' => OrderStatus::InProgress]);
        OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'unit_price_cents' => 100000]);
        app(RecalculateOrderTotals::class)->handle($order);
        $paid = Payment::factory()->paid()->create(['order_id' => $order->id, 'sequence' => 1, 'amount_cents' => 30000]);
        $overdue = Payment::factory()->overdue()->create(['order_id' => $order->id, 'sequence' => 2, 'amount_cents' => 20000]);
        $upcoming = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 3, 'amount_cents' => 50000, 'due_date' => today()->addDays(5)->toDateString()]);
        Lead::factory()->create(['client_id' => $this->client->id, 'owner_id' => $this->agent->id]);
        // Another seller's order for the same client is not part of this seller's 360.
        Order::factory()->create(['client_id' => $this->client->id, 'owner_id' => $this->bravo->agent(0)->id]);
        $this->actingAsUser($this->agent);

        $this->getJson("/api/clients/{$this->client->id}")
            ->assertOk()
            ->assertJsonPath('data.counts', ['leads' => 1, 'orders' => 1, 'open_orders' => 1, 'overdue_payments' => 1])
            ->assertJsonPath('data.lifetime_value.amount_cents', 30000)
            ->assertJsonCount(1, 'data.leads')
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.total.amount_cents', 100000)
            ->assertJsonPath('data.orders.0.amount_paid.amount_cents', 30000)
            ->assertJsonPath('data.orders.0.balance.formatted', '$700.00')
            ->assertJsonPath('data.orders.0.overdue_payments_count', 1)
            ->assertJsonPath('data.overdue_payments.0.id', $overdue->id)
            ->assertJsonPath('data.upcoming_payments.0.id', $upcoming->id)
            ->assertJsonCount(1, 'data.upcoming_payments');
        expect($paid->exists)->toBeTrue();
    });

    it('returns 403 out of scope and 404 when missing', function () {
        $foreign = Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
        $this->actingAsUser($this->agent);

        $this->getJson("/api/clients/{$this->client->id}")->assertOk();
        $this->getJson("/api/clients/{$foreign->id}")->assertForbidden();
        $this->getJson('/api/clients/999999')->assertNotFound();
    });
});

describe('update', function () {
    it('applies directly for a team lead and for support (200)', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'By TL', 'status' => 'nurturing'])
            ->assertOk()->assertJsonPath('data.notes', 'By TL')->assertJsonPath('data.status.value', 'nurturing');

        $this->actingAsRole(RoleName::Support);
        $this->putJson("/api/clients/{$this->client->id}", ['nurturing_rating' => 55])->assertOk();

        expect($this->client->fresh()->nurturing_rating)->toBe(55)
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('queues the change for a sales executive (202) and shows the pending change', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'New notes', 'reason' => 'Call notes'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes', ['notes' => 'New notes'])
            ->assertJsonPath('data.reason', 'Call notes');

        expect($this->client->fresh()->notes)->toBe('Original');
        $this->getJson("/api/clients/{$this->client->id}")->assertJsonPath('data.pending_change.fields', ['notes']);

        $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'Again'])->assertUnprocessable();
    });

    it('rejects a no-op, a duplicate username and denies out-of-scope users', function () {
        $other = Client::factory()->create(['owner_id' => $this->agent->id]);
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'Original'])->assertUnprocessable();
        $this->patchJson("/api/clients/{$this->client->id}", ['discord_username' => $other->discord_username])
            ->assertUnprocessable()->assertJsonValidationErrors(['discord_username']);

        $this->actingAsUser($this->bravo->teamLead);
        $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'x'])->assertForbidden();
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();
        expect(Client::query()->find($this->client->id))->toBeNull();
    });

    it('queues the deletion for a team lead and a sales executive (202)', function (string $who) {
        $this->actingAsUser($who === 'team lead' ? $this->alpha->teamLead : $this->agent);

        $this->deleteJson("/api/clients/{$this->client->id}", ['reason' => 'Duplicate'])->assertAccepted();

        expect($this->client->fresh())->not->toBeNull()
            ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);
    })->with(['team lead', 'sales executive']);

    it('denies deletion out of scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->deleteJson("/api/clients/{$this->client->id}")->assertForbidden();
    });
});

describe('approval round trip', function () {
    it('applies a queued update and a queued delete when support approves', function () {
        $this->actingAsUser($this->agent);
        $update = $this->patchJson("/api/clients/{$this->client->id}", ['notes' => 'Approved notes'])->assertAccepted()->json('data.id');

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$update}/approve")->assertOk();
        expect($this->client->fresh()->notes)->toBe('Approved notes');

        $this->actingAsUser($this->agent);
        $delete = $this->deleteJson("/api/clients/{$this->client->id}")->assertAccepted()->json('data.id');

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$delete}/approve")->assertOk();
        expect(Client::query()->find($this->client->id))->toBeNull();
    });
});
