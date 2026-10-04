<?php

use App\Enums\LeadStage;
use App\Enums\RoleName;
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

it('creates a valid persisted model from every factory', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue()
        ->and($model::query()->count())->toBe(1);
})->with([
    'team' => Team::class,
    'workstation' => Workstation::class,
    'user' => User::class,
    'service' => Service::class,
    'platform account' => PlatformAccount::class,
    'social account' => SocialAccount::class,
    'client' => Client::class,
    'lead' => Lead::class,
    'order' => Order::class,
    'order item' => OrderItem::class,
    'payment' => Payment::class,
    'approval request' => ApprovalRequest::class,
]);

it('assigns roles through the user factory states', function () {
    expect(User::factory()->admin()->create()->hasRole(RoleName::Admin->value))->toBeTrue()
        ->and(User::factory()->support()->create()->hasRole(RoleName::Support->value))->toBeTrue()
        ->and(User::factory()->teamLead()->create()->hasRole(RoleName::TeamLead->value))->toBeTrue()
        ->and(User::factory()->salesExecutive()->create()->hasRole(RoleName::SalesExecutive->value))->toBeTrue()
        ->and(User::factory()->inactive()->create()->is_active)->toBeFalse();
});

it('generates sequential order numbers per year', function () {
    $first = Order::factory()->create(['ordered_on' => '2026-03-01']);
    $second = Order::factory()->create(['ordered_on' => '2026-04-01']);
    $other = Order::factory()->create(['ordered_on' => '2025-04-01']);

    expect($first->order_number)->toBe('SH-2026-00001')
        ->and($second->order_number)->toBe('SH-2026-00002')
        ->and($other->order_number)->toBe('SH-2025-00001');
});

it('sets and bumps the lead stage timestamp', function () {
    $lead = Lead::factory()->create(['stage_changed_at' => now()->subDays(10)]);
    $before = $lead->stage_changed_at;

    $lead->update(['stage' => LeadStage::Engaged]);

    expect($lead->stage_changed_at->gt($before))->toBeTrue();
});

it('exposes lead stage helpers', function () {
    expect(LeadStage::ordered())->toHaveCount(7)
        ->and(LeadStage::ordered()[0])->toBe(LeadStage::New)
        ->and(LeadStage::Quoted->isOpen())->toBeTrue()
        ->and(LeadStage::Won->isOpen())->toBeFalse()
        ->and(LeadStage::Lost->isOpen())->toBeFalse()
        ->and(LeadStage::PortfolioShared->label())->toBe('Portfolio Shared');
});
