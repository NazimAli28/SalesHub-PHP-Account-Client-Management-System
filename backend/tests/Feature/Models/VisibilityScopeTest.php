<?php

use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Spatie\Permission\Models\Permission;

/**
 * @param  list<string>  $permissions
 */
function userWith(array $permissions, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
        $user->givePermissionTo($name);
    }

    return $user;
}

beforeEach(function () {
    $this->alpha = Team::factory()->create();
    $this->bravo = Team::factory()->create();
    $this->stationA = Workstation::factory()->create(['team_id' => $this->alpha->id]);
    $this->stationB = Workstation::factory()->create(['team_id' => $this->bravo->id]);
    $this->agentA = User::factory()->create(['team_id' => $this->alpha->id, 'workstation_id' => $this->stationA->id]);
    $this->agentA2 = User::factory()->create(['team_id' => $this->alpha->id]);
    $this->agentB = User::factory()->create(['team_id' => $this->bravo->id, 'workstation_id' => $this->stationB->id]);
});

it('returns nothing for users without any view permission', function () {
    Lead::factory()->create(['owner_id' => $this->agentA->id]);

    expect(Lead::query()->visibleTo(User::factory()->create())->count())->toBe(0)
        ->and(Client::query()->visibleTo($this->agentA)->count())->toBe(0);
});

it('scopes leads by view-all, view-team and view-own', function () {
    $mine = Lead::factory()->create(['owner_id' => $this->agentA->id]);
    $teammate = Lead::factory()->create(['owner_id' => $this->agentA2->id]);
    $other = Lead::factory()->create(['owner_id' => $this->agentB->id]);

    $all = userWith(['leads.view-all']);
    $teamLead = userWith(['leads.view-team'], ['team_id' => $this->alpha->id]);
    $teamless = userWith(['leads.view-team']);
    $agent = userWith(['leads.view-own'], ['team_id' => $this->alpha->id]);
    $agentLead = Lead::factory()->create(['owner_id' => $agent->id]);

    expect(Lead::query()->visibleTo($all)->count())->toBe(4)
        ->and(Lead::query()->visibleTo($teamLead)->pluck('id')->sort()->values()->all())->toBe([$mine->id, $teammate->id, $agentLead->id])
        ->and(Lead::query()->visibleTo($teamless)->count())->toBe(0)
        ->and(Lead::query()->visibleTo($agent)->pluck('id')->all())->toBe([$agentLead->id])
        ->and(Lead::query()->visibleTo($this->agentA)->count())->toBe(0)
        ->and($other->exists)->toBeTrue();
});

it('scopes platform accounts and their social accounts by workstation', function () {
    $own = PlatformAccount::factory()->assigned($this->stationA)->create();
    $teamOther = PlatformAccount::factory()->assigned(Workstation::factory()->create(['team_id' => $this->alpha->id]))->create();
    $foreign = PlatformAccount::factory()->assigned($this->stationB)->create();
    $unassigned = PlatformAccount::factory()->create();
    SocialAccount::factory()->create(['platform_account_id' => $own->id]);
    SocialAccount::factory()->create(['platform_account_id' => $foreign->id]);

    $agent = userWith(['platform-accounts.view-own', 'social-accounts.view-own'], ['team_id' => $this->alpha->id, 'workstation_id' => $this->stationA->id]);
    $lead = userWith(['platform-accounts.view-team'], ['team_id' => $this->alpha->id]);
    $admin = userWith(['platform-accounts.view-all', 'social-accounts.view-all']);

    expect(PlatformAccount::query()->visibleTo($agent)->pluck('id')->all())->toBe([$own->id])
        ->and(PlatformAccount::query()->visibleTo($lead)->pluck('id')->sort()->values()->all())->toBe([$own->id, $teamOther->id])
        ->and(PlatformAccount::query()->visibleTo($admin)->count())->toBe(4)
        ->and(SocialAccount::query()->visibleTo($agent)->count())->toBe(1)
        ->and(SocialAccount::query()->visibleTo($admin)->count())->toBe(2)
        ->and($unassigned->exists)->toBeTrue();
});

it('scopes clients, orders and payments through ownership and team', function () {
    $client = Client::factory()->create(['owner_id' => $this->agentA2->id]);
    $order = Order::factory()->create(['client_id' => $client->id, 'owner_id' => $this->agentA->id, 'team_id' => $this->alpha->id]);
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    $foreignOrder = Order::factory()->create(['owner_id' => $this->agentB->id, 'team_id' => $this->bravo->id]);
    Payment::factory()->create(['order_id' => $foreignOrder->id]);

    $agentA = $this->agentA;
    foreach (['clients.view-own', 'orders.view-own'] as $name) {
        Permission::findOrCreate($name, 'web');
        $agentA->givePermissionTo($name);
    }
    $teamLead = userWith(['clients.view-team', 'orders.view-team'], ['team_id' => $this->alpha->id]);
    $bravoLead = userWith(['clients.view-team', 'orders.view-team'], ['team_id' => $this->bravo->id]);

    expect(Client::query()->visibleTo($agentA)->pluck('id')->all())->toBe([$client->id])
        ->and(Order::query()->visibleTo($agentA)->pluck('id')->all())->toBe([$order->id])
        ->and(Payment::query()->visibleTo($agentA)->pluck('id')->all())->toBe([$payment->id])
        ->and(Client::query()->visibleTo($teamLead)->pluck('id')->all())->toBe([$client->id])
        ->and(Order::query()->visibleTo($teamLead)->pluck('id')->all())->toBe([$order->id])
        ->and(Order::query()->visibleTo($bravoLead)->pluck('id')->all())->toBe([$foreignOrder->id]);
});

it('scopes approval requests and users', function () {
    $mine = ApprovalRequest::factory()->create(['requested_by_id' => $this->agentA->id]);
    ApprovalRequest::factory()->create(['requested_by_id' => $this->agentB->id]);

    $agent = userWith(['approvals.view-own']);
    $lead = userWith(['approvals.view-team'], ['team_id' => $this->alpha->id]);
    $admin = userWith(['approvals.view-all', 'users.view', 'users.update']);
    $teamViewer = userWith(['users.view'], ['team_id' => $this->alpha->id]);

    expect(ApprovalRequest::query()->visibleTo($this->agentA)->count())->toBe(0)
        ->and(ApprovalRequest::query()->visibleTo($lead)->pluck('id')->all())->toBe([$mine->id])
        ->and(ApprovalRequest::query()->visibleTo($admin)->count())->toBe(2)
        ->and(ApprovalRequest::query()->visibleTo($agent)->count())->toBe(0)
        ->and(User::query()->visibleTo($admin)->count())->toBe(User::query()->count())
        ->and(User::query()->visibleTo($teamViewer)->pluck('id')->sort()->values()->all())->toBe([$this->agentA->id, $this->agentA2->id, $lead->id, $teamViewer->id])
        ->and(User::query()->visibleTo($this->agentA)->pluck('id')->all())->toBe([$this->agentA->id]);
});
