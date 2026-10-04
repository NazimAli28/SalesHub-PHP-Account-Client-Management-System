<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\Service;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

it('soft deletes and restores business models', function (string $model) {
    $record = $model::factory()->create();

    $record->delete();
    expect($model::query()->count())->toBe(0)
        ->and($model::withTrashed()->count())->toBe(1);

    $record->restore();
    expect($model::query()->count())->toBe(1);
})->with([
    Team::class, Workstation::class, User::class, Service::class, PlatformAccount::class,
    SocialAccount::class, Client::class, Lead::class, Order::class, Payment::class,
]);

it('seeds the full demo dataset on SQLite', function () {
    $this->seed(DatabaseSeeder::class);

    expect(DB::table('teams')->count())->toBe(4)
        ->and(DB::table('workstations')->count())->toBe(12)
        ->and(DB::table('users')->count())->toBe(14)
        ->and(DB::table('services')->count())->toBe(12)
        ->and(DB::table('platform_accounts')->count())->toBe(60)
        ->and(DB::table('platform_accounts')->whereNull('workstation_id')->count())->toBe(12)
        ->and(DB::table('social_accounts')->count())->toBe(120)
        ->and(DB::table('clients')->count())->toBe(150)
        ->and(DB::table('leads')->count())->toBe(400)
        ->and(DB::table('orders')->count())->toBe(180)
        ->and(DB::table('approval_requests')->count())->toBe(40)
        ->and(DB::table('approval_requests')->where('status', 'pending')->count())->toBe(12)
        ->and(DB::table('notifications')->count())->toBe(60)
        ->and(DB::table('activity_log')->count())->toBe(300);

    $agent = User::query()->where('username', 'agent1')->firstOrFail();
    expect($agent->hasRole('sales_executive'))->toBeTrue()
        ->and(password_verify('Demo@12345', $agent->password))->toBeTrue()
        ->and($agent->workstation->code)->toBe('PC-01')
        ->and(User::query()->where('username', 'admin')->firstOrFail()->hasRole('admin'))->toBeTrue()
        ->and(Team::query()->where('name', 'Unit 1 Alpha')->firstOrFail()->teamLead->username)->toBe('tl');
});

it('keeps seeded order totals consistent with items and payments', function () {
    $this->seed(DatabaseSeeder::class);

    $bad = Order::query()->withCount('items')->get()->filter(function (Order $order) {
        $items = (int) $order->items()->sum('line_total_cents');
        $scheduled = (int) $order->payments()->sum('amount_cents');

        return $order->subtotal_cents !== $items
            || $order->total_cents !== max(0, $items - $order->discount_cents)
            || $scheduled !== $order->total_cents;
    });

    expect($bad)->toHaveCount(0);
});
