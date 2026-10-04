<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum LeadLostReason: string
{
    case NoResponse = 'no_response';
    case Price = 'price';
    case ChoseCompetitor = 'chose_competitor';
    case NotReady = 'not_ready';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
