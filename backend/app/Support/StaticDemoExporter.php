<?php

namespace App\Support;

use App\Analytics\AnalyticsScope;
use App\Analytics\DateRange;
use App\Analytics\OverviewReport;
use App\Enums\AccountStanding;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\ServiceCategory;
use App\Enums\Shift;
use App\Enums\SocialPlatform;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\Analytics\OverviewResource;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Resources\ClientNoteResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\MeResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PlatformAccountResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\SocialAccountResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkstationResource;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\ClientNote;
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
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Builds the data set of the static browser demo from a throw-away SQLite database seeded with the
 * normal seeders. Records are rendered through the real API Resources; nested relation objects are
 * reduced to their IDs (the in-browser API rebuilds them), and secrets never leave the database:
 * Resources already hide credentials and password hashes, and nothing here reads them.
 */
final class StaticDemoExporter
{
    private const CONNECTION = 'static_demo_export';

    /** Dashboard range presets of the SPA (features/dashboard/api.ts), in days. */
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => 365];

    /** @var list<class-string<BackedEnum>> */
    private const ENUMS = [
        AccountStanding::class, ApprovalAction::class, ApprovalStatus::class, ClientStatus::class,
        LeadLostReason::class, LeadStage::class, OrderStatus::class, OrderType::class, PaymentMethod::class,
        PaymentStatus::class, RoleName::class, ServiceCategory::class, Shift::class, SocialPlatform::class,
    ];

    public function __construct(private readonly OverviewReport $report) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $file = storage_path('framework/static-demo-'.bin2hex(random_bytes(6)).'.sqlite');
        File::put($file, '');

        $originalDefault = config('database.default');
        $originalConfig = [
            'cache.default' => config('cache.default'),
            'queue.default' => config('queue.default'),
            'mail.default' => config('mail.default'),
            'saleshub.demo_mode' => config('saleshub.demo_mode'),
        ];

        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => self::CONNECTION,
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            // Masks IPs in the activity entries and reports `demo_mode` in /auth/me, as on the public demo.
            'saleshub.demo_mode' => true,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--seed' => true, '--force' => true]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $this->build();
        } finally {
            DB::purge(self::CONNECTION);
            config(['database.default' => $originalDefault, ...$originalConfig]);
            DB::setDefaultConnection(is_string($originalDefault) ? $originalDefault : 'sqlite');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            try {
                File::delete($file);
            } catch (Throwable) {
                // A locked temp file on Windows is harmless; it lives in storage/framework.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $request = Request::create('/api/export');
        $request->setUserResolver(fn () => $admin);

        $now = CarbonImmutable::now('UTC');

        return [
            'version' => 1,
            'exported_at' => $now->toIso8601ZuluString(),
            'export_date' => $now->toDateString(),
            'demo_usernames' => DemoAccounts::usernames(),
            'enums' => $this->enums(),
            'role_permissions' => $this->rolePermissions(),
            'users' => $this->resources(UserResource::class, User::query()->with(['roles', 'team', 'workstation'])->orderBy('id')->get()->all(), $request),
            'me' => $this->meByUser($request),
            'teams' => $this->resources(TeamResource::class, Team::query()->orderBy('id')->get()->all(), $request),
            'workstations' => $this->resources(WorkstationResource::class, Workstation::query()->orderBy('id')->get()->all(), $request),
            'services' => $this->resources(ServiceResource::class, Service::query()->orderBy('id')->get()->all(), $request),
            'clients' => $this->resources(ClientResource::class, Client::query()->orderBy('id')->get()->all(), $request),
            'client_notes' => $this->clientNotes($request),
            'leads' => $this->leads($request),
            'orders' => $this->resources(OrderResource::class, Order::query()->orderBy('id')->get()->all(), $request, ['amount_paid', 'balance', 'overdue_payments_count']),
            'order_items' => $this->resources(OrderItemResource::class, OrderItem::query()->with('order')->orderBy('id')->get()->all(), $request),
            'payments' => $this->resources(PaymentResource::class, Payment::query()->orderBy('id')->get()->all(), $request, ['is_overdue']),
            'platform_accounts' => $this->resources(PlatformAccountResource::class, PlatformAccount::query()->orderBy('id')->get()->all(), $request),
            'social_accounts' => $this->resources(SocialAccountResource::class, SocialAccount::query()->orderBy('id')->get()->all(), $request),
            'approvals' => $this->approvals($request),
            'notifications' => $this->notifications($request),
            'activities' => $this->resources(
                ActivityResource::class,
                Activity::query()->with(['causer', 'subject'])->orderBy('id')->get()->all(),
                $request,
                ['causer'],
            ),
            'analytics' => $this->analytics($request),
        ];
    }

    /**
     * Renders models through a Resource and drops nested relation objects (the browser API rebuilds them).
     *
     * @param  class-string<JsonResource>  $resource
     * @param  array<int, mixed>  $models
     * @param  list<string>  $drop
     * @return list<array<string, mixed>>
     */
    private function resources(string $resource, array $models, Request $request, array $drop = []): array
    {
        $nested = ['client', 'owner', 'closer', 'platform_account', 'workstation', 'team', 'team_lead', 'services', 'parent', 'items', 'payments', 'order', 'recorded_by', 'service', 'social_accounts', 'members', 'users', 'leads', 'orders', 'requester', 'reviewer', 'author'];

        return array_values(array_map(function (mixed $model) use ($resource, $request, $drop, $nested): array {
            $row = (new $resource($model))->resolve($request);
            if ($resource === UserResource::class) {
                // A user keeps its small team/workstation stubs: they are part of /auth/me and the users list.
                return Arr::except($row, $drop);
            }

            return Arr::except($row, [...$nested, ...$drop]);
        }, $models));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function enums(): array
    {
        $enums = [];
        foreach (self::ENUMS as $enum) {
            $labels = [];
            foreach ($enum::cases() as $case) {
                $labels[(string) $case->value] = (string) $case->label();
            }
            $enums[class_basename($enum)] = $labels;
        }

        return $enums;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolePermissions(): array
    {
        $roles = [];
        foreach (PermissionMatrix::matrix() as $role => $permissions) {
            $list = $permissions;
            sort($list);
            $roles[$role] = $list;
        }

        return $roles;
    }

    /**
     * The /auth/me body of every demo user (shared accounts: no two-factor, demo mode on).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function meByUser(Request $request): array
    {
        $me = [];
        foreach (User::query()->with(['roles', 'team', 'workstation'])->orderBy('id')->get() as $user) {
            $row = (new MeResource($user))->resolve($request);
            $row['two_factor_enabled'] = false;
            $row['demo_mode'] = true;
            $me[(string) $user->id] = $row;
        }

        return $me;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clientNotes(Request $request): array
    {
        $notes = ClientNote::query()->orderBy('id')->get();

        return array_values(array_map(function (ClientNote $note) use ($request): array {
            $row = Arr::except((new ClientNoteResource($note))->resolve($request), ['author', 'can_edit', 'can_delete']);

            return [...$row, 'author_id' => $note->user_id];
        }, $notes->all()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leads(Request $request): array
    {
        $leads = Lead::query()->with('services:id')->orderBy('id')->get();
        $rows = $this->resources(LeadResource::class, $leads->all(), $request);

        foreach ($leads->values() as $index => $lead) {
            $rows[$index]['currency'] = $lead->currency;
            $rows[$index]['service_ids'] = $lead->services->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvals(Request $request): array
    {
        $approvals = ApprovalRequest::query()->orderBy('id')->get();

        return array_values(array_map(function (ApprovalRequest $approval) use ($request): array {
            $row = Arr::except((new ApprovalRequestResource($approval))->resolve($request), ['requester', 'reviewer', 'diff', 'can']);

            return [...$row, 'requested_by_id' => $approval->requested_by_id, 'reviewed_by_id' => $approval->reviewed_by_id];
        }, $approvals->all()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(Request $request): array
    {
        $notifications = DatabaseNotification::query()->where('notifiable_type', 'user')->orderBy('created_at')->get();

        return array_values(array_map(fn (DatabaseNotification $notification): array => [
            ...(new NotificationResource($notification))->resolve($request),
            'user_id' => (int) $notification->notifiable_id,
        ], $notifications->all()));
    }

    /**
     * Precomputed dashboard responses: every active user x range preset, plus the team filter for
     * users who see all teams. Identical bodies are stored once. `pending_approvals` is left out:
     * the browser API counts it live from its own approval queue.
     *
     * @return array{snapshots: array<string, mixed>, index: array<string, string>}
     */
    private function analytics(Request $request): array
    {
        $snapshots = [];
        $index = [];
        $today = CarbonImmutable::today();
        $teamIds = Team::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (User::query()->where('is_active', true)->orderBy('id')->get() as $user) {
            $teams = $user->can('reports.view-all') ? [null, ...$teamIds] : [null];

            foreach (self::PRESETS as $preset => $days) {
                $range = new DateRange($today->subDays($days - 1), $today);

                foreach ($teams as $teamId) {
                    $body = (new OverviewResource($this->report->build(AnalyticsScope::resolve($user, $teamId, null), $range)))->resolve($request);
                    unset($body['kpis']['pending_approvals']);
                    $hash = substr(md5((string) json_encode($body)), 0, 12);
                    $snapshots[$hash] = $body;
                    $index[$user->id.'|'.$preset.'|'.($teamId ?? '')] = $hash;
                }
            }
        }

        return ['snapshots' => $snapshots, 'index' => $index];
    }
}
