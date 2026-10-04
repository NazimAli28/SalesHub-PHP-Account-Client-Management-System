<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ServiceCategory: string
{
    case Branding = 'branding';
    case Emotes = 'emotes';
    case Overlays = 'overlays';
    case Packages = 'packages';
    case Animation = 'animation';
    case Other = 'other';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
