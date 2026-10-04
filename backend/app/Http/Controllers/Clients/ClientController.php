<?php

namespace App\Http\Controllers\Clients;

use App\Actions\Clients\CreateClient;
use App\Actions\Clients\DeleteClient;
use App\Actions\Clients\UpdateClient;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\ClientIndexQuery;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientDetailResource;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        return ClientResource::collection(ApiPagination::paginate(ClientIndexQuery::make($request), $request));
    }

    public function store(StoreClientRequest $request, CreateClient $createClient): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $client = $createClient->handle($user, $request->clientAttributes());

        return ClientResource::make($this->loadForResponse($client))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Client 360: leads, orders with balance, upcoming and overdue payments, and counts, all limited to what
     * the user can see (a sales executive sees the client's leads and orders they own).
     */
    public function show(Request $request, Client $client): ClientDetailResource
    {
        Gate::authorize('view', $client);

        /** @var User $user */
        $user = $request->user();

        $client->loadCount([
            'leads' => fn ($q) => $q->visibleTo($user),
            'orders' => fn ($q) => $q->visibleTo($user),
            'orders as open_orders_count' => fn ($q) => $q->visibleTo($user)->open(),
            'payments as overdue_payments_count' => fn ($q) => $q->overdue()->visibleTo($user),
        ]);
        $client->loadSum(
            ['payments as lifetime_value_cents' => fn ($q) => $q->where('payments.status', PaymentStatus::Paid->value)],
            'amount_cents',
        );
        $client->load([
            ...ClientResource::DEFAULT_WITH,
            'owner',
            'leads' => fn ($q) => $q->visibleTo($user)->latest('contacted_on')->latest('id'),
            'orders' => fn ($q) => $q->visibleTo($user)->withPaymentTotals()->withOverdueCount()->latest('ordered_on')->latest('id'),
        ]);

        $client->setRelation('upcomingPayments', $this->clientPayments($client, $user)
            ->where('status', PaymentStatus::Scheduled->value)
            ->whereDate('due_date', '>=', today()->toDateString())
            ->orderBy('due_date')->limit(20)->get());
        $client->setRelation('overduePayments', $this->clientPayments($client, $user)
            ->overdue()->orderBy('due_date')->limit(50)->get());

        return ClientDetailResource::make($client);
    }

    /**
     * 200 with the client (direct write) or 202 with the queued approval request.
     */
    public function update(UpdateClientRequest $request, Client $client, UpdateClient $updateClient): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updateClient->prepare($client, $request->changes());

        return $this->updateOrRequestChange(
            $user,
            $client,
            $changes,
            apply: fn () => ClientResource::make($this->loadForResponse($updateClient->handle($client, $changes))),
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, Client $client, DeleteClient $deleteClient): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $client);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $client,
            apply: function () use ($deleteClient, $client): Response {
                $deleteClient->handle($client);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    /**
     * @return Builder<Payment>
     */
    private function clientPayments(Client $client, User $user): Builder
    {
        return Payment::query()
            ->visibleTo($user)
            ->whereHas('order', fn ($o) => $o->where('client_id', $client->id))
            ->with('order');
    }

    private function loadForResponse(Client $client): Client
    {
        return $client->load([...ClientResource::DEFAULT_WITH, 'owner']);
    }
}
