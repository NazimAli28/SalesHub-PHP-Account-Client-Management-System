<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\MarkPaymentPaid;
use App\Actions\Payments\UpdatePayment;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\MarkPaymentPaidRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/payments/{payment}/mark-paid: 200 with the paid payment, or 202 when queued for approval.
 */
class MarkPaymentPaidController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function __invoke(MarkPaymentPaidRequest $request, Payment $payment, MarkPaymentPaid $markPaid, UpdatePayment $updatePayment): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $markPaid->changes($user, $request->paidInput());

        return $this->updateOrRequestChange(
            $user,
            $payment,
            $changes,
            apply: fn () => PaymentResource::make(PaymentController::loadForResponse($updatePayment->handle($payment, $changes))),
            reason: $request->reason(),
        );
    }
}
