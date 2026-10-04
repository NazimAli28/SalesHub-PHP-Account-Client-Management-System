<?php

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ManageOrderItems;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderItemRequest;
use App\Http\Requests\Orders\UpdateOrderItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nested item endpoints. Direct writes only (roles that may update the order); each returns the parent order
 * with recalculated totals.
 */
class OrderItemController extends Controller
{
    public function store(StoreOrderItemRequest $request, Order $order, ManageOrderItems $items): JsonResponse
    {
        $items->add($order, $request->itemData());

        return OrderResource::make(OrderController::loadForResponse($order))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item, ManageOrderItems $items): OrderResource
    {
        $items->update($order, $item, $request->changes());

        return OrderResource::make(OrderController::loadForResponse($order));
    }

    public function destroy(Request $request, Order $order, OrderItem $item, ManageOrderItems $items): OrderResource
    {
        Gate::authorize('update', $order);

        $items->remove($order, $item);

        return OrderResource::make(OrderController::loadForResponse($order));
    }
}
