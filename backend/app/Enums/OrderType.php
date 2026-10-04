<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum OrderType: string
{
    case Fresh = 'fresh';
    case Upsell = 'upsell';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
