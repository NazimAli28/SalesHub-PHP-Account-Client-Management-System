<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ApprovalAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case RequestAccounts = 'request_accounts';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
