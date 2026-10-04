<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum AccountStanding: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Spam = 'spam';
    case Violation = 'violation';
    case Disabled = 'disabled';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
