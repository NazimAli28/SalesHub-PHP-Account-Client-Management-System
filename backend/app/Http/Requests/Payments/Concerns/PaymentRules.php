<?php

namespace App\Http\Requests\Payments\Concerns;

use App\Actions\Payments\PaymentSchedule;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Field rules shared by the payment Form Requests. Prefix each rule list with 'required' or 'sometimes'.
 */
trait PaymentRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function paymentAttributeRules(): array
    {
        return [
            'amount_cents' => ['integer', 'min:1', 'max:100000000'],
            'currency' => ['string', 'regex:/^[A-Z]{3}$/'],
            'due_date' => ['date_format:Y-m-d'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Adds the order-total / currency violation of the schedule to the validator (same check the action repeats).
     */
    protected function checkScheduleFits(Validator $validator, Order $order, int $amountCents, string $currency, ?Payment $payment = null): void
    {
        try {
            app(PaymentSchedule::class)->ensureFits($order, $amountCents, $currency, $payment);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
