<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ClientStatus: string
{
    case Active = 'active';
    case Nurturing = 'nurturing';
    case Dormant = 'dormant';
    case Lost = 'lost';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
