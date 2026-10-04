<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case X = 'x';
    case Behance = 'behance';
    case Dribbble = 'dribbble';
    case ArtStation = 'artstation';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Pinterest = 'pinterest';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::ArtStation => 'ArtStation',
            default => Str::headline($this->name),
        };
    }
}
