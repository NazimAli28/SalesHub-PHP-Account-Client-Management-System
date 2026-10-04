<?php

use App\Enums\ApprovalStatus;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\Service;
use Tests\Support\OrderFixtures;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->client = Client::factory()->create(['owner_id' => $this->agent->id]);
    $this->logo = Service::factory()->create(['base_price_cents' => 15000]);
    $this->emotes = Service::factory()->create(['base_price_cents' => 4000]);
    $this->order = OrderFixtures::make($this->agent, 100000);
});

describe('store', function () {
    it('creates an order with items and correct totals in cents (201)', function () {
        $this->actingAsUser($this->agent);

        $response = $this->postJson('/api/orders', [
            'client_id' => $this->client->id,
            'discount_cents' => 1000,
            'items' => [
                ['service_id' => $this->logo->id, 'quantity' => 2],
                ['service_id' => $this->emotes->id, 'quantity' => 3, 'unit_price_cents' => 3500, 'description' => '3 sub badges'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status.value', 'pending_payment')
            ->assertJsonPath('data.type.value', 'fresh')
            ->assertJsonPath('data.owner_id', $this->agent->id)
            ->assertJsonPath('data.team_id', $this->alpha->team->id)
            ->assertJsonPath('data.subtotal.amount_cents', 40500)
            ->assertJsonPath('data.discount.amount_cents', 1000)
            ->assertJsonPath('data.total.amount_cents', 39500)
            ->assertJsonPath('data.balance.amount_cents', 39500)
            ->assertJsonPath('data.ordered_on', today()->toDateString())
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.unit_price.amount_cents', 15000)
            ->assertJsonPath('data.items.0.line_total.amount_cents', 30000)
            ->assertJsonPath('data.items.1.line_total.amount_cents', 10500);

        expect($response->json('data.order_number'))->toMatch('/^SH-\d{4}-\d{5}$/')
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });

    it('marks a linked lead as won and links an upsell to its parent order', function () {
        $lead = Lead::factory()->quoted()->create(['client_id' => $this->client->id, 'owner_id' => $this->agent->id]);
        $parent = OrderFixtures::make($this->agent, 1000, ['client_id' => $this->client->id]);
        $this->actingAsUser($this->agent);

        $response = $this->postJson('/api/orders', [
            'client_id' => $this->client->id,
            'lead_id' => $lead->id,
            'parent_order_id' => $parent->id,
            'items' => [['service_id' => $this->logo->id]],
        ])->assertCreated()->assertJsonPath('data.type.value', 'upsell')->assertJsonPath('data.total.amount_cents', 15000);

        $lead->refresh();
        expect($lead->stage)->toBe(LeadStage::Won)->and($lead->order_id)->toBe($response->json('data.id'));
    });

    it('validates the payload (422)', function () {
        $this->actingAsUser($this->agent);
        $item = ['service_id' => $this->logo->id];

        $this->postJson('/api/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'items']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => []])->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => [['quantity' => 0, 'unit_price_cents' => -1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_id', 'items.0.quantity', 'items.0.unit_price_cents']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => [$item], 'currency' => 'usd', 'status' => 'nope', 'ordered_on' => today()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency', 'status', 'ordered_on']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => [$item], 'discount_cents' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_cents']);

        $inactive = Service::factory()->create(['is_active' => false]);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => [['service_id' => $inactive->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_id']);
    });

    it('rejects references the user cannot see (422)', function () {
        $foreignClient = Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
        $foreignOrder = OrderFixtures::make($this->bravo->agent(0));
        $foreignAccount = PlatformAccount::factory()->assigned($this->bravo->station(0))->create();
        $foreignLead = Lead::factory()->create(['client_id' => $this->client->id, 'owner_id' => $this->bravo->agent(0)->id]);
        $this->actingAsUser($this->agent);
        $items = [['service_id' => $this->logo->id]];

        $this->postJson('/api/orders', ['client_id' => $foreignClient->id, 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors(['client_id']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => $items, 'parent_order_id' => $foreignOrder->id])->assertUnprocessable()->assertJsonValidationErrors(['parent_order_id']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => $items, 'platform_account_id' => $foreignAccount->id])->assertUnprocessable()->assertJsonValidationErrors(['platform_account_id']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => $items, 'lead_id' => $foreignLead->id])->assertUnprocessable()->assertJsonValidationErrors(['lead_id']);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => $items, 'owner_id' => $this->alpha->agent(1)->id])->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);

        $other = OrderFixtures::make($this->agent);
        $this->postJson('/api/orders', ['client_id' => $this->client->id, 'items' => $items, 'parent_order_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_order_id']);
    });
});

describe('show', function () {
    it('returns items and payments, 403 out of scope, 404 when missing', function () {
        Payment::factory()->create(['order_id' => $this->order->id, 'amount_cents' => 5000]);
        $foreign = OrderFixtures::make($this->bravo->agent(0));
        $this->actingAsUser($this->agent);

        $this->getJson("/api/orders/{$this->order->id}")->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.amount.amount_cents', 5000)
            ->assertJsonPath('data.items.0.service.id', $this->order->items->first()->service_id);
        $this->getJson("/api/orders/{$foreign->id}")->assertForbidden();
        $this->getJson('/api/orders/999999')->assertNotFound();
    });
});

describe('update', function () {
    it('applies directly for a team lead and recalculates totals when the discount changes', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/orders/{$this->order->id}", ['discount_cents' => 20000, 'status' => 'delivered', 'notes' => 'Done'])
            ->assertOk()
            ->assertJsonPath('data.subtotal.amount_cents', 100000)
            ->assertJsonPath('data.total.amount_cents', 80000)
            ->assertJsonPath('data.status.value', 'delivered')
            ->assertJsonPath('data.notes', 'Done');

        expect($this->order->fresh()->delivered_at)->not->toBeNull();
    });

    it('queues the change for a sales executive (202) and leaves the order unchanged', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/orders/{$this->order->id}", ['discount_cents' => 10000, 'reason' => 'Loyalty discount'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes', ['discount_cents' => 10000]);

        expect($this->order->fresh()->total_cents)->toBe(100000);
        $this->getJson("/api/orders/{$this->order->id}")->assertJsonPath('data.pending_change.fields', ['discount_cents']);
        $this->patchJson("/api/orders/{$this->order->id}", ['notes' => 'x'])->assertUnprocessable();
    });

    it('recalculates the totals when a queued discount is approved', function () {
        $this->actingAsUser($this->agent);
        $approval = $this->patchJson("/api/orders/{$this->order->id}", ['discount_cents' => 10000])->assertAccepted()->json('data.id');

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();

        $order = $this->order->fresh();
        expect($order->discount_cents)->toBe(10000)->and($order->total_cents)->toBe(90000);
    });

    it('rejects fields that cannot change and discounts above the subtotal (422)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/orders/{$this->order->id}", ['client_id' => 1, 'owner_id' => 1, 'currency' => 'EUR', 'items' => [['service_id' => 1]], 'total_cents' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'owner_id', 'currency', 'items', 'total_cents']);
        $this->patchJson("/api/orders/{$this->order->id}", ['discount_cents' => 100001])
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_cents']);
    });

    it('refuses a discount that drops the total below the payments already scheduled', function () {
        Payment::factory()->create(['order_id' => $this->order->id, 'amount_cents' => 100000]);
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/orders/{$this->order->id}", ['discount_cents' => 5000])
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_cents']);
        expect($this->order->fresh()->total_cents)->toBe(100000);
    });

    it('denies updates out of scope (403)', function () {
        $this->actingAsUser($this->bravo->teamLead);
        $this->patchJson("/api/orders/{$this->order->id}", ['notes' => 'x'])->assertForbidden();

        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/orders/{$this->order->id}", ['notes' => 'x'])->assertForbidden();
    });
});

describe('items', function () {
    it('adds, updates and removes items and keeps totals correct (support)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->postJson("/api/orders/{$this->order->id}/items", ['service_id' => $this->logo->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.subtotal.amount_cents', 130000)
            ->assertJsonPath('data.total.amount_cents', 130000)
            ->assertJsonCount(2, 'data.items');

        $newItem = $this->order->items()->where('service_id', $this->logo->id)->firstOrFail();
        $this->patchJson("/api/orders/{$this->order->id}/items/{$newItem->id}", ['quantity' => 3, 'unit_price_cents' => 10000])
            ->assertOk()->assertJsonPath('data.total.amount_cents', 130000);
        expect($newItem->fresh()->line_total_cents)->toBe(30000);

        $this->deleteJson("/api/orders/{$this->order->id}/items/{$newItem->id}")
            ->assertOk()->assertJsonPath('data.total.amount_cents', 100000)->assertJsonCount(1, 'data.items');

        $last = $this->order->items()->firstOrFail();
        $this->deleteJson("/api/orders/{$this->order->id}/items/{$last->id}")->assertUnprocessable()->assertJsonValidationErrors(['item']);
    });

    it('allows a team lead but denies a sales executive and other teams', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->postJson("/api/orders/{$this->order->id}/items", ['service_id' => $this->emotes->id])->assertCreated();

        $this->actingAsUser($this->agent);
        $this->postJson("/api/orders/{$this->order->id}/items", ['service_id' => $this->emotes->id])->assertForbidden();

        $this->actingAsUser($this->bravo->teamLead);
        $this->postJson("/api/orders/{$this->order->id}/items", ['service_id' => $this->emotes->id])->assertForbidden();
    });

    it('validates items, scopes the item to its order and protects payments and closed orders', function () {
        $otherOrder = OrderFixtures::make($this->agent);
        $this->actingAsRole(RoleName::Support);
        $item = $this->order->items()->firstOrFail();

        $this->postJson("/api/orders/{$this->order->id}/items", [])->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
        $this->patchJson("/api/orders/{$otherOrder->id}/items/{$item->id}", ['quantity' => 2])->assertNotFound();

        Payment::factory()->create(['order_id' => $this->order->id, 'amount_cents' => 100000]);
        $this->patchJson("/api/orders/{$this->order->id}/items/{$item->id}", ['unit_price_cents' => 50000])->assertUnprocessable();
        expect($this->order->fresh()->total_cents)->toBe(100000);

        $this->order->update(['status' => OrderStatus::Cancelled]);
        $this->postJson("/api/orders/{$this->order->id}/items", ['service_id' => $this->logo->id])->assertUnprocessable()->assertJsonValidationErrors(['order']);
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->deleteJson("/api/orders/{$this->order->id}")->assertNoContent();
        expect(Order::query()->find($this->order->id))->toBeNull();
    });

    it('queues the deletion for team lead and sales executive, approved by support (202)', function (string $who) {
        $this->actingAsUser($who === 'team lead' ? $this->alpha->teamLead : $this->agent);

        $approval = $this->deleteJson("/api/orders/{$this->order->id}", ['reason' => 'Wrong client'])->assertAccepted()->json('data.id');
        expect($this->order->fresh())->not->toBeNull()
            ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();
        expect(Order::query()->find($this->order->id))->toBeNull();
    })->with(['team lead', 'sales executive']);

    it('denies deletion out of scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));
        $this->deleteJson("/api/orders/{$this->order->id}")->assertForbidden();
    });
});
