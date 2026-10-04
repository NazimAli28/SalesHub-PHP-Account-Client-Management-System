<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum Shift: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Night = 'night';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
