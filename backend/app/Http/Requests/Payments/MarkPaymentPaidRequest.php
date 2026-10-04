<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentStatus;
use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\Payments\Concerns\PaymentRules;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * POST /api/payments/{payment}/mark-paid. Same update-vs-queue rule as an update: support/admin/team lead record the
 * payment directly, a sales executive queues it for approval.
 */
class MarkPaymentPaidRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, PaymentRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('payment'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->paymentAttributeRules();

        return [
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'method' => $rules['method'],
            'reference' => $rules['reference'],
            'notes' => $rules['notes'],
            'reason' => $rules['reason'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $payment = $this->route('payment');

            if ($payment instanceof Payment && $payment->status !== PaymentStatus::Scheduled) {
                $validator->errors()->add('status', 'Only a scheduled payment can be marked as paid.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function paidInput(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}
