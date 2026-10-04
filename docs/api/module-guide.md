# SalesHub v2 — Module guide

Checklist for adding an API module (Clients, Orders, Payments, Platform Accounts, Social Accounts, ...). **Leads is the reference module**: when in doubt, copy it. Read [conventions.md](conventions.md) first.

Placeholders below: `{Module}` = plural folder name (`Clients`), `{Model}` = model class (`Client`), `{models}` = URL segment (`clients`), `{alias}` = morph alias (`client`), `{perm}` = permission prefix (`clients`).

## 0. Ground rules for parallel work

- Touch only your module's files. Shared files (`routes/api.php`, `AppServiceProvider`, `tests/Pest.php`, anything under `app/Approvals` except your own applier, `app/Http/Resources/Concerns`, `app/Http/Filters`, `app/Http/Queries/ApiPagination.php`) are owned by the platform. If you need a change there, ask; do not edit.
- Models, enums, policies, factories and visibility scopes already exist (Phase 1). Do not rewrite them; small additions (a scope, a helper method) are fine.
- Every index query starts from `{Model}::query()->visibleTo($user)`. Every single-record endpoint authorizes through the policy (403 for out-of-scope records, never 404).

## 1. Files to create

| Purpose | Path | Lead example |
|---------|------|--------------|
| Routes | `routes/api/{models}.php` | `routes/api/leads.php` |
| Controller(s) | `app/Http/Controllers/{Module}/{Model}Controller.php` (+ single-action controllers for extra endpoints) | `Leads/LeadController.php`, `Leads/LeadStageController.php` |
| Index query | `app/Http/Queries/{Model}IndexQuery.php` | `LeadIndexQuery.php` |
| Form Requests | `app/Http/Requests/{Module}/Store{Model}Request.php`, `Update{Model}Request.php` (+ shared rules trait in `{Module}/Concerns/`) | `Leads/StoreLeadRequest.php`, `Leads/Concerns/LeadRules.php` |
| Actions | `app/Actions/{Module}/Create{Model}.php`, `Update{Model}.php`, `Delete{Model}.php` | `Actions/Leads/*` |
| Resource | `app/Http/Resources/{Model}Resource.php` (compact embeds go in `Resources/Summaries/`) | `LeadResource.php` |
| Approval applier | `app/Approvals/Appliers/{Studly alias}Applier.php` | `LeadApplier.php` |
| Tests | `tests/Feature/Api/{Module}/{Model}IndexTest.php`, `{Model}WriteTest.php` (+ one file per extra endpoint) | `tests/Feature/Api/Leads/*` |

## 2. Routes

`routes/api.php` loads every `routes/api/*.php` inside the authenticated group (`auth:sanctum`, `active`, `ip.allowed`). Your file contains only your routes, no middleware group, no prefix:

```php
<?php

use App\Http\Controllers\Clients\ClientController;
use Illuminate\Support\Facades\Route;

Route::apiResource('clients', ClientController::class);
// Extra actions: verb + sub-resource, named {models}.{action}
Route::patch('clients/{client}/status', ClientStatusController::class)->name('clients.status');
```

The route parameter name must equal the controller variable name (`{client}` ↔ `Client $client`) for implicit binding.

## 3. Index query

A final class with a static `make(Request): QueryBuilder<{Model}>` (do **not** extend `QueryBuilder`; its constructor is final by contract):

```php
return QueryBuilder::for({Model}::query()->visibleTo($user)->with({Model}Resource::DEFAULT_WITH), $request)
    ->allowedFilters(
        AllowedFilter::exact('status'),                                   // filter[status]=active,dormant
        AllowedFilter::exact('owner', 'owner_id'),                        // public name, column
        AllowedFilter::custom('created_from', new DateFilter('>='), 'created_at'),
        AllowedFilter::custom('created_to', new DateFilter('<='), 'created_at'),
        AllowedFilter::custom('search', new SearchFilter(['name', 'email', 'owner.name'])),
    )
    ->allowedSorts('created_at', 'name', 'id')
    ->defaultSort('-created_at', '-id')
    ->allowedIncludes(...{Model}Resource::INCLUDES);
```

Controller: `return {Model}Resource::collection(ApiPagination::paginate({Model}IndexQuery::make($request), $request));` after `Gate::authorize('viewAny', {Model}::class)`. Document the filters, sorts and includes in the class docblock.

## 4. Form Requests

- `authorize()`:
  - Store: `$this->user()?->can('create', {Model}::class)`.
  - Update/stage/etc. (update-vs-queue endpoints): `use AuthorizesChangeOrRequest;` then `return $this->canChangeOrRequest('update', $this->route('{model}'));`.
  - Direct-only actions (e.g. reassign): `$this->user()?->can('{ability}', $record)`.
- Presence: Store uses `required`/`nullable`/`sometimes`; Update puts `'sometimes'` first on every field (PUT and PATCH are both partial).
- **Every reference to a scoped model uses `VisibleTo`**, never a plain `exists`:

  ```php
  'client_id' => ['required', 'integer', new VisibleTo(Client::class, $user)],
  'owner_id'  => ['nullable', 'integer', new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))],
  ```

  Unscoped catalogs (services) use `Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at')`.
- Enums: `Rule::enum(X::class)` (`->except([...])` to forbid values). Money: `*_cents` → `integer|min:0`, `currency` → `regex:/^[A-Z]{3}$/`. Dates: `date_format:Y-m-d`.
- Cross-field rules go in `after(): array` returning closures (see `LeadRules::lostReasonCheck`).
- Update endpoints accept `'reason' => ['nullable', 'string', 'max:500']` (stored on the approval request).
- Fields that must never change through update (owner, links set by other modules): `['prohibited']` with a helpful message in `messages()`.
- Expose typed accessors instead of letting controllers dig into `validated()`: `changes()`, `serviceIds()`, `reason()`, ...
- Never accept encrypted credential columns in an update that may be queued: credentials are support/admin direct writes on their own endpoint.

## 5. Actions (all writes)

One class per write in `app/Actions/{Module}`, constructor-injected, wrapped in `DB::transaction` when it touches more than one row.

- `Create{Model}::handle(User $actor, array $data, ...): {Model}`
- `Update{Model}::prepare({Model} $record, array $validated): array` — pure; turns input into the full change set (derived fields, cleared fields). The result is what gets applied **or** queued, so the reviewer sees the real effect.
- `Update{Model}::handle({Model} $record, array $changes, ?array $relationIds = null): {Model}` — the only code that writes updates. Called by the controller (direct) and by your applier (approved request).
- `Delete{Model}::handle({Model} $record): void` — soft delete.
- Money totals: never compute in controllers; Orders must call `App\Actions\Orders\RecalculateOrderTotals` in the same transaction that writes items.

## 6. Controller and the update-vs-queue rule

```php
class ClientController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $updateClient): JsonResource|Response
    {
        $changes = $updateClient->prepare($client, $request->changes());

        return $this->updateOrRequestChange(
            $request->user(), $client, $changes,
            apply: fn () => ClientResource::make($updateClient->handle($client, $changes)->load(ClientResource::DEFAULT_WITH)),
            relations: [],                 // BelongsToMany name => full ID list, e.g. ['services' => [1, 4]]
            reason: $request->reason(),
        );
    }

    public function destroy(Request $request, Client $client, DeleteClient $deleteClient): JsonResource|Response
    {
        $this->authorizeChangeOrRequest($request->user(), 'delete', $client);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion($request->user(), $client,
            apply: function () use ($deleteClient, $client) { $deleteClient->handle($client); return response()->noContent(); },
            reason: $validated['reason'] ?? null,
        );
    }
}
```

`updateOrRequestChange`/`deleteOrRequestDeletion` (in `App\Http\Controllers\Concerns\RoutesChangesThroughApprovals`) check `update`/`delete` → apply (200/204), else `requestChange` → `SubmitChangeRequest` → 202 with `ApprovalRequestResource`, else 403. Policies must expose `update`, `delete` and `requestChange` (all `ScopedResourcePolicy` subclasses do; `PaymentPolicy` has them explicitly). `SubmitChangeRequest` stores only fields that really differ (422 "There are no changes to submit." otherwise) and rejects a second pending request for the same record (422).

Other rules: `store` returns `->response()->setStatusCode(201)`; `show` calls `Gate::authorize('view', $record)`; controllers never call `Model::create/update` directly.

## 7. Resource

```php
/** @mixin Client */
class ClientResource extends JsonResource
{
    use FormatsApiValues;

    public const DEFAULT_WITH = ['pendingApproval.requester'];   // always eager-loaded
    public const INCLUDES = ['owner', 'leads'];                  // ?include= whitelist

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->enum($this->status),                                  // {value, label}
            'lifetime_value' => $this->money($this->lifetime_value_cents, 'USD'),     // {amount_cents, currency, formatted}
            'expected_upsell_on' => $this->date($this->expected_upsell_on),          // YYYY-MM-DD
            'owner_id' => $this->owner_id,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'pending_change' => $this->pendingChange(),                              // approvable models only
            'created_at' => $this->dateTime($this->created_at),                      // ISO 8601 UTC
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
```

Always output the raw `*_id` foreign keys; embed related records only via `whenLoaded`, using `Resources/Summaries/*` (add a summary there if you need a new one, e.g. `OrderSummaryResource`). Never output hidden/encrypted columns.

## 8. Approval applier

Approved requests are applied by `App\Actions\Approvals\ApproveRequest` through `App\Approvals\ApplierRegistry`, which finds the applier **by naming convention** — no registration list to edit:

| Morph alias | Applier class | Status |
|-------------|---------------|--------|
| `lead` | `App\Approvals\Appliers\LeadApplier` | done (calls `UpdateLead`/`DeleteLead`) |
| `client` | `ClientApplier` | generic fill/save + soft delete; override `update`/`delete` to call your actions |
| `platform_account` | `PlatformAccountApplier` | generic; override `update` for `standing_changed_at` / `assigned_at` side effects |
| `social_account` | `SocialAccountApplier` | generic |
| `order` | `OrderApplier` | **missing — Orders module must add** (totals via `RecalculateOrderTotals`, items) |
| `payment` | `PaymentApplier` | **missing — Payments module must add** (currency/sum invariants, paid payments) |
| action `request_accounts` | `RequestAccountsApplier` | done (records the decision only) |

`SubmitChangeRequest` refuses to queue a request for an alias without an applier (`LogicException`), so add the applier before wiring the 202 path. Template:

```php
class OrderApplier extends AttributeApplier
{
    public function __construct(private readonly UpdateOrder $updateOrder, private readonly DeleteOrder $deleteOrder) {}

    protected function model(): string { return Order::class; }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var Order $record */
        return $this->updateOrder->handle($record, $changes);
    }

    protected function delete(Model $record): Model
    {
        /** @var Order $record */
        $this->deleteOrder->handle($record);

        return $record;
    }
}
```

The applier runs inside the approval transaction with the target row locked, after the staleness check. Payload shape: `payload.changes` (attributes), `payload.relations` (BelongsToMany name → ID list). Model activity entries written while applying automatically get `properties.approval_request_id`.

## 9. Morph alias

Approval requests and activity entries store the morph alias, and the morph map is enforced. The aliases `platform_account`, `social_account`, `client`, `lead`, `order`, `payment`, `user`, `team`, `workstation`, `service`, `order_item`, `approval_request` already exist in `AppServiceProvider::boot`. A **new** model needs a new alias there (ask the platform owner). An approvable model also needs `use HasApprovals` (all approvable models already have it).

## 10. Tests

Tests in `tests/Feature/Api/**` automatically get `RefreshDatabase`, the seeded roles/permissions and the `InteractsWithApi` helpers:

| Helper | Purpose |
|--------|---------|
| `$this->makeTeamWithMembers(2)` | `TeamFixture`: `->team`, `->teamLead`, `->agent(0)`, `->station(0)` (each agent on own workstation) |
| `$this->actingAsUser($user)` | authenticate (safe to switch users mid-test) |
| `$this->actingAsRole(RoleName::Support)` / `('support')` | create + authenticate |
| `$this->makeUser(RoleName, attrs)`, `$this->makeStaff(RoleName, $team, $station)` | create without authenticating |
| `app(SubmitChangeRequest::class)->update($user, $record, [...])` | seed a pending request without HTTP |

Copy `tests/Feature/Api/Leads/*` and cover at least:

1. Index scoping per role: SE own, TL team, support/admin all (use two teams).
2. Each filter, one sort asc/desc, unknown sort/filter/include → 400, pagination meta (`page[size]`, `page[number]`).
3. Value formats: enum `{value,label}`, money `formatted`, dates.
4. Store 201 (incl. SE where allowed), validation 422 per rule, out-of-scope referenced IDs → 422.
5. Show 200 / 403 out of scope / 404 missing.
6. Update direct 200 (TL/support), queued 202 for SE with the record unchanged and `pending_change` shown, second pending → 422, no-op → 422, out of scope → 403.
7. Delete direct 204 (support) vs queued 202 (TL/SE), out of scope → 403.
8. Applier: approve a queued update and a queued delete via `POST /api/approvals/{id}/approve` and assert the record changed.
9. Every extra endpoint: permission denial 403 plus its business rules.

Run before handing over: `php vendor/bin/pint --test`, `php vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `php vendor/bin/pest`.
