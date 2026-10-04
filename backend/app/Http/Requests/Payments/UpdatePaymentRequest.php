<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentStatus;
use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\Payments\Concerns\PaymentRules;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT and PATCH are both partial updates. A paid payment can only be edited directly by support or admin
 * (PaymentPolicy::update); a team lead or sales executive queues the edit for approval. Use mark-paid to record a payment.
 */
class UpdatePaymentRequest extends FormRequest
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
            'order_id' => ['prohibited'],
            'sequence' => ['prohibited'],
            'currency' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'recorded_by_id' => ['prohibited'],
            'amount_cents' => ['sometimes', ...$rules['amount_cents']],
            'due_date' => ['sometimes', ...$rules['due_date']],
            'status' => ['sometimes', Rule::in([PaymentStatus::Scheduled->value, PaymentStatus::Void->value])],
            'method' => ['sometimes', ...$rules['method']],
            'reference' => ['sometimes', ...$rules['reference']],
            'notes' => ['sometimes', ...$rules['notes']],
            'reason' => $rules['reason'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Use POST /api/payments/{payment}/mark-paid to record a payment; status can only be set to scheduled or void here.',
            'currency.prohibited' => 'Payments always use the order currency.',
            'paid_at.prohibited' => 'Use POST /api/payments/{payment}/mark-paid to record when a payment was made.',
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $payment = $this->route('payment');

            if ($validator->errors()->isNotEmpty() || ! $payment instanceof Payment) {
                return;
            }

            $status = $this->has('status') ? $this->input('status') : $payment->status->value;
            if ($status === PaymentStatus::Void->value) {
                return;
            }

            $this->checkScheduleFits(
                $validator,
                $payment->order()->firstOrFail(),
                (int) $this->input('amount_cents', $payment->amount_cents),
                $payment->currency,
                $payment,
            );
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}
