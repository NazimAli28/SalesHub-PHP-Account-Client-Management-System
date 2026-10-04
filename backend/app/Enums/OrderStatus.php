<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
