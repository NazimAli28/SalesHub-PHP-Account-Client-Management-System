<?php

use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;

it('links teams, workstations, users and platform accounts', function () {
    $team = Team::factory()->create();
    $lead = User::factory()->teamLead()->create(['team_id' => $team->id]);
    $team->update(['team_lead_id' => $lead->id]);
    $station = Workstation::factory()->create(['team_id' => $team->id]);
    $agent = User::factory()->salesExecutive()->create(['team_id' => $team->id, 'workstation_id' => $station->id]);
    $account = PlatformAccount::factory()->assigned($station)->create();

    expect($team->teamLead->is($lead))->toBeTrue()
        ->and($lead->ledTeam->is($team))->toBeTrue()
        ->and($team->members)->toHaveCount(2)
        ->and($team->workstations->first()->is($station))->toBeTrue()
        ->and($station->users->first()->is($agent))->toBeTrue()
        ->and($station->platformAccounts->first()->is($account))->toBeTrue()
        ->and($account->workstation->team->is($team))->toBeTrue()
        ->and($team->display_name)->toBe("{$team->name} (Floor {$team->floor} {$team->shift->label()})");
});

it('links clients, leads, orders, items and payments', function () {
    $owner = User::factory()->salesExecutive()->create();
    $client = Client::factory()->create(['owner_id' => $owner->id]);
    $service = Service::factory()->create();
    $lead = Lead::factory()->create(['client_id' => $client->id, 'owner_id' => $owner->id]);
    $lead->services()->attach($service);
    $order = Order::factory()->create(['client_id' => $client->id, 'owner_id' => $owner->id]);
    $lead->update(['order_id' => $order->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id, 'service_id' => $service->id]);
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    $upsell = Order::factory()->create(['client_id' => $client->id, 'owner_id' => $owner->id, 'parent_order_id' => $order->id]);

    expect($client->owner->is($owner))->toBeTrue()
        ->and($client->leads->first()->is($lead))->toBeTrue()
        ->and($client->orders)->toHaveCount(2)
        ->and($client->payments->first()->is($payment))->toBeTrue()
        ->and($lead->services->first()->is($service))->toBeTrue()
        ->and($service->leads->first()->is($lead))->toBeTrue()
        ->and($order->lead->is($lead))->toBeTrue()
        ->and($lead->order->is($order))->toBeTrue()
        ->and($order->items->first()->is($item))->toBeTrue()
        ->and($item->service->is($service))->toBeTrue()
        ->and($order->payments->first()->is($payment))->toBeTrue()
        ->and($order->upsells->first()->is($upsell))->toBeTrue()
        ->and($upsell->parent->is($order))->toBeTrue()
        ->and($owner->leads)->toHaveCount(1)
        ->and($owner->orders)->toHaveCount(2)
        ->and($owner->clients)->toHaveCount(1);
});

it('links social accounts and approval requests', function () {
    $account = PlatformAccount::factory()->create();
    $social = SocialAccount::factory()->create(['platform_account_id' => $account->id]);
    $lead = Lead::factory()->create();
    $request = ApprovalRequest::factory()->create(['approvable_id' => $lead->id]);

    expect($account->socialAccounts->first()->is($social))->toBeTrue()
        ->and($social->platformAccount->is($account))->toBeTrue()
        ->and($request->approvable->is($lead))->toBeTrue()
        ->and($request->requester)->toBeInstanceOf(User::class)
        ->and($lead->pendingApproval->is($request))->toBeTrue()
        ->and($lead->approvalRequests)->toHaveCount(1)
        ->and($request->requester->approvalRequests->first()->is($request))->toBeTrue();
});

it('derives account has_client and client lifetime value', function () {
    $account = PlatformAccount::factory()->create();
    $unused = PlatformAccount::factory()->create();
    $order = Order::factory()->create(['platform_account_id' => $account->id]);
    Payment::factory()->paid()->create(['order_id' => $order->id, 'sequence' => 1, 'amount_cents' => 5000]);
    Payment::factory()->create(['order_id' => $order->id, 'sequence' => 2, 'amount_cents' => 7000]);

    $flags = PlatformAccount::query()->withHasClient()->get()->pluck('has_client', 'id');
    $client = Client::query()->withLifetimeValue()->findOrFail($order->client_id);

    expect((bool) $flags[$account->id])->toBeTrue()
        ->and((bool) $flags[$unused->id])->toBeFalse()
        ->and((int) $client->lifetime_value_cents)->toBe(5000);
});
