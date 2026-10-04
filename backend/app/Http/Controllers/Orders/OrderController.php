<?php

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\DeleteOrder;
use App\Actions\Orders\UpdateOrder;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\OrderIndexQuery;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        return OrderResource::collection(ApiPagination::paginate(OrderIndexQuery::make($request), $request));
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $createOrder->handle($user, $request->orderAttributes(), $request->items(), $request->lead());

        return OrderResource::make(self::loadForResponse($order))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return OrderResource::make(self::loadForResponse($order));
    }

    /**
     * 200 with the order (direct write) or 202 with the queued approval request.
     */
    public function update(UpdateOrderRequest $request, Order $order, UpdateOrder $updateOrder): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updateOrder->prepare($order, $request->changes());

        return $this->updateOrRequestChange(
            $user,
            $order,
            $changes,
            apply: fn () => OrderResource::make(self::loadForResponse($updateOrder->handle($order, $changes))),
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, Order $order, DeleteOrder $deleteOrder): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $order);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $order,
            apply: function () use ($deleteOrder, $order): Response {
                $deleteOrder->handle($order);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    /**
     * Reloads the order with its aggregates (paid, overdue) and the detail relations.
     */
    public static function loadForResponse(Order $order): Order
    {
        return Order::query()
            ->withPaymentTotals()
            ->withOverdueCount()
            ->with([...OrderResource::DEFAULT_WITH, 'client', 'owner', 'closer', 'team', 'platformAccount', 'items.service', 'payments'])
            ->findOrFail($order->getKey());
    }
}
