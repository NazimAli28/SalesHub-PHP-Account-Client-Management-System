<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum PaymentMethod: string
{
    case PayPal = 'paypal';
    case Stripe = 'stripe';
    case Wise = 'wise';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PayPal => 'PayPal',
            default => Str::headline($this->name),
        };
    }
}
