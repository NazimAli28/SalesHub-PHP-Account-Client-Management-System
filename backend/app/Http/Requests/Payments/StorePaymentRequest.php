<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Payments\Concerns\PaymentRules;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/orders/{order}/payments: schedules an installment (always status `scheduled`).
 */
class StorePaymentRequest extends FormRequest
{
    use PaymentRules;

    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && (bool) $this->user()?->can('create', [Payment::class, $order]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->paymentAttributeRules();

        return [
            'amount_cents' => ['required', ...$rules['amount_cents']],
            'due_date' => ['required', ...$rules['due_date']],
            'currency' => ['sometimes', ...$rules['currency']],
            'method' => $rules['method'],
            'reference' => $rules['reference'],
            'notes' => $rules['notes'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $order = $this->route('order');

            if ($validator->errors()->isNotEmpty() || ! $order instanceof Order) {
                return;
            }

            $this->checkScheduleFits($validator, $order, (int) $this->input('amount_cents'), (string) $this->input('currency', $order->currency));
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAttributes(): array
    {
        return $this->validated();
    }
}
