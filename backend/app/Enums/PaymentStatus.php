<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum PaymentStatus: string
{
    case Scheduled = 'scheduled';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
