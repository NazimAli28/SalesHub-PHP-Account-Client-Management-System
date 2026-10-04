<?php

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\Workstation;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = Team::factory()->create();
    $this->bravo = Team::factory()->create();
    $this->stationA = Workstation::factory()->create(['team_id' => $this->alpha->id]);
    $this->stationA2 = Workstation::factory()->create(['team_id' => $this->alpha->id]);
    $this->stationB = Workstation::factory()->create(['team_id' => $this->bravo->id]);

    $this->agentA = $this->makeStaff(RoleName::SalesExecutive, $this->alpha, $this->stationA);
    $this->agentA2 = $this->makeStaff(RoleName::SalesExecutive, $this->alpha, $this->stationA2);
    $this->agentB = $this->makeStaff(RoleName::SalesExecutive, $this->bravo, $this->stationB);
    $this->leadA = $this->makeStaff(RoleName::TeamLead, $this->alpha);
    $this->leadB = $this->makeStaff(RoleName::TeamLead, $this->bravo);
    $this->support = $this->makeUser(RoleName::Support);
    $this->admin = $this->makeUser(RoleName::Admin);

    $this->accountA = PlatformAccount::factory()->assigned($this->stationA)->create();
    $this->accountA2 = PlatformAccount::factory()->assigned($this->stationA2)->create();
    $this->accountB = PlatformAccount::factory()->assigned($this->stationB)->create();
    $this->unassigned = PlatformAccount::factory()->create();
});

function visibleIds(string $model, $user): array
{
    return $model::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
}

it('limits a sales executive to the platform account of their own workstation', function () {
    expect(visibleIds(PlatformAccount::class, $this->agentA))->toBe([$this->accountA->id])
        ->and($this->agentA->can('view', $this->accountA))->toBeTrue()
        ->and($this->agentA->can('view', $this->accountA2))->toBeFalse()
        ->and($this->agentA->can('view', $this->accountB))->toBeFalse()
        ->and($this->agentA->can('view', $this->unassigned))->toBeFalse();

    $denied = Gate::forUser($this->agentA)->inspect('view', $this->accountB);
    expect($denied->allowed())->toBeFalse();
});

it('limits a team lead to their team for accounts, leads and orders', function () {
    $mine = Lead::factory()->create(['owner_id' => $this->agentA->id]);
    $teammate = Lead::factory()->create(['owner_id' => $this->agentA2->id]);
    $other = Lead::factory()->create(['owner_id' => $this->agentB->id]);

    $order = Order::factory()->create(['owner_id' => $this->agentA->id, 'team_id' => $this->alpha->id]);
    $otherOrder = Order::factory()->create(['owner_id' => $this->agentB->id, 'team_id' => $this->bravo->id]);

    expect(visibleIds(Lead::class, $this->leadA))->toBe([$mine->id, $teammate->id])
        ->and($this->leadA->can('view', $mine))->toBeTrue()
        ->and($this->leadA->can('update', $teammate))->toBeTrue()
        ->and($this->leadA->can('view', $other))->toBeFalse()
        ->and($this->leadA->can('update', $other))->toBeFalse()
        ->and($this->leadA->can('reassign', $teammate))->toBeTrue()
        ->and($this->leadA->can('reassign', $other))->toBeFalse()
        ->and($this->leadA->can('delete', $mine))->toBeFalse()
        ->and(visibleIds(Order::class, $this->leadA))->toBe([$order->id])
        ->and($this->leadA->can('view', $otherOrder))->toBeFalse()
        ->and(visibleIds(PlatformAccount::class, $this->leadA))->toBe([$this->accountA->id, $this->accountA2->id])
        ->and($this->leadA->can('view', $this->accountB))->toBeFalse();
});

it('limits a sales executive to their own leads and routes changes through requests', function () {
    $mine = Lead::factory()->create(['owner_id' => $this->agentA->id]);
    $teammate = Lead::factory()->create(['owner_id' => $this->agentA2->id]);

    expect(visibleIds(Lead::class, $this->agentA))->toBe([$mine->id])
        ->and($this->agentA->can('update', $mine))->toBeFalse()
        ->and($this->agentA->can('requestChange', $mine))->toBeTrue()
        ->and($this->agentA->can('requestChange', $teammate))->toBeFalse()
        ->and($this->agentA->can('view', $teammate))->toBeFalse()
        ->and($this->agentA->can('create', Lead::class))->toBeTrue()
        ->and($this->agentA->can('reassign', $mine))->toBeFalse();
});

it('lets support and admin see every record including unassigned accounts', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->agentB->id]);
    $client = Client::factory()->create(['owner_id' => $this->agentA->id]);

    foreach ([$this->support, $this->admin] as $user) {
        expect(PlatformAccount::query()->visibleTo($user)->count())->toBe(4)
            ->and($user->can('view', $this->unassigned))->toBeTrue()
            ->and($user->can('view', $lead))->toBeTrue()
            ->and($user->can('view', $client))->toBeTrue()
            ->and($user->can('update', $lead))->toBeTrue()
            ->and($user->can('delete', $lead))->toBeTrue();
    }
});

it('follows the parent platform account for social accounts', function () {
    $mine = SocialAccount::factory()->create(['platform_account_id' => $this->accountA->id]);
    $theirs = SocialAccount::factory()->create(['platform_account_id' => $this->accountB->id]);

    expect(visibleIds(SocialAccount::class, $this->agentA))->toBe([$mine->id])
        ->and($this->agentA->can('view', $theirs))->toBeFalse()
        ->and($this->agentA->can('revealCredentials', $mine))->toBeTrue()
        ->and($this->agentA->can('revealCredentials', $theirs))->toBeFalse()
        ->and($this->leadA->can('revealCredentials', $mine))->toBeFalse()
        ->and($this->leadA->can('view', $mine))->toBeTrue()
        ->and($this->support->can('revealCredentials', $theirs))->toBeTrue();
});

it('applies the platform account permissions per role', function () {
    expect($this->agentA->can('update', $this->accountA))->toBeFalse()
        ->and($this->agentA->can('requestChange', $this->accountA))->toBeTrue()
        ->and($this->agentA->can('requestNew', PlatformAccount::class))->toBeTrue()
        ->and($this->agentA->can('assign', $this->accountA))->toBeFalse()
        ->and($this->leadA->can('requestChange', $this->accountA))->toBeTrue()
        ->and($this->leadA->can('changeStanding', $this->accountA))->toBeFalse()
        ->and($this->support->can('assign', $this->unassigned))->toBeTrue()
        ->and($this->support->can('changeStanding', $this->accountB))->toBeTrue()
        ->and($this->support->can('create', PlatformAccount::class))->toBeTrue()
        ->and($this->leadA->can('create', PlatformAccount::class))->toBeFalse();
});

it('scopes payments through their order and blocks edits to paid ones', function () {
    $order = Order::factory()->create(['owner_id' => $this->agentA->id, 'team_id' => $this->alpha->id]);
    $otherOrder = Order::factory()->create(['owner_id' => $this->agentB->id, 'team_id' => $this->bravo->id]);
    $open = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 1, 'status' => PaymentStatus::Scheduled]);
    $paid = Payment::factory()->paid()->create(['order_id' => $order->id, 'sequence' => 2]);
    $foreign = Payment::factory()->create(['order_id' => $otherOrder->id]);

    expect(visibleIds(Payment::class, $this->agentA))->toBe([$open->id, $paid->id])
        ->and($this->agentA->can('view', $foreign))->toBeFalse()
        ->and($this->agentA->can('create', [Payment::class, $order]))->toBeTrue()
        ->and($this->agentA->can('create', [Payment::class, $otherOrder]))->toBeFalse()
        ->and($this->leadA->can('update', $open))->toBeTrue()
        ->and($this->leadA->can('update', $paid))->toBeFalse()
        ->and($this->leadA->can('update', $foreign))->toBeFalse()
        ->and($this->support->can('update', $paid))->toBeTrue()
        ->and($this->admin->can('update', $paid))->toBeTrue()
        ->and($this->leadA->can('delete', $open))->toBeFalse()
        ->and($this->support->can('delete', $open))->toBeTrue()
        ->and($this->agentA->can('requestChange', $paid))->toBeTrue();
});

it('restricts services, teams, workstations and the audit log', function () {
    $service = Service::factory()->create();

    expect($this->agentA->can('viewAny', Service::class))->toBeTrue()
        ->and($this->agentA->can('update', $service))->toBeFalse()
        ->and($this->support->can('update', $service))->toBeTrue()
        ->and($this->support->can('update', $this->alpha))->toBeFalse()
        ->and($this->admin->can('update', $this->alpha))->toBeTrue()
        ->and($this->leadA->can('viewAny', Workstation::class))->toBeTrue()
        ->and($this->leadA->can('update', $this->stationA))->toBeFalse()
        ->and($this->admin->can('viewAny', Activity::class))->toBeTrue()
        ->and($this->support->can('viewAny', Activity::class))->toBeFalse();
});

it('applies the client scope through leads and orders', function () {
    $own = Client::factory()->create(['owner_id' => $this->agentB->id]);
    Lead::factory()->create(['client_id' => $own->id, 'owner_id' => $this->agentA->id]);
    $foreign = Client::factory()->create(['owner_id' => $this->agentB->id]);

    expect(visibleIds(Client::class, $this->agentA))->toBe([$own->id])
        ->and($this->agentA->can('view', $own))->toBeTrue()
        ->and($this->agentA->can('view', $foreign))->toBeFalse()
        ->and($this->leadA->can('update', $own))->toBeTrue()
        ->and($this->leadA->can('update', $foreign))->toBeFalse();
});
