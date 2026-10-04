<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\CreatePayment;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\PaymentIndexQuery;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installments nested under an order: list (same filters as GET /api/payments) and schedule a new one.
 */
class OrderPaymentController extends Controller
{
    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        Gate::authorize('view', $order);

        return PaymentResource::collection(ApiPagination::paginate(PaymentIndexQuery::make($request, $order), $request));
    }

    public function store(StorePaymentRequest $request, Order $order, CreatePayment $createPayment): JsonResponse
    {
        $payment = $createPayment->handle($order, $request->paymentAttributes());

        return PaymentResource::make(PaymentController::loadForResponse($payment))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
