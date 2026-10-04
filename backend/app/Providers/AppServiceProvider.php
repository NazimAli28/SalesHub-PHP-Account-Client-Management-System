<?php

namespace App\Providers;

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
use App\Policies\ActivityPolicy;
use App\Support\LoginThrottle;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The seven approvable models come from the data model spec; the others are
        // needed because the activity log stores polymorphic subjects for them too.
        Relation::enforceMorphMap([
            'platform_account' => PlatformAccount::class,
            'social_account' => SocialAccount::class,
            'client' => Client::class,
            'lead' => Lead::class,
            'order' => Order::class,
            'payment' => Payment::class,
            'user' => User::class,
            'team' => Team::class,
            'workstation' => Workstation::class,
            'service' => Service::class,
            'order_item' => OrderItem::class,
        ]);

        Password::defaults(fn () => Password::min(10)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->when($this->app->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        RateLimiter::for(LoginThrottle::NAME, LoginThrottle::limits(...));

        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
