<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\DeletePayment;
use App\Actions\Payments\UpdatePayment;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\PaymentIndexQuery;
use App\Http\Requests\Payments\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payment::class);

        return PaymentResource::collection(ApiPagination::paginate(PaymentIndexQuery::make($request), $request));
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return PaymentResource::make(self::loadForResponse($payment));
    }

    /**
     * 200 with the payment (direct write) or 202 with the queued approval request.
     */
    public function update(UpdatePaymentRequest $request, Payment $payment, UpdatePayment $updatePayment): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updatePayment->prepare($payment, $request->changes());

        return $this->updateOrRequestChange(
            $user,
            $payment,
            $changes,
            apply: fn () => PaymentResource::make(self::loadForResponse($updatePayment->handle($payment, $changes))),
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, Payment $payment, DeletePayment $deletePayment): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $payment);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $payment,
            apply: function () use ($deletePayment, $payment): Response {
                $deletePayment->handle($payment);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    public static function loadForResponse(Payment $payment): Payment
    {
        return $payment->load([...PaymentResource::DEFAULT_WITH, 'order.client', 'recordedBy']);
    }
}
