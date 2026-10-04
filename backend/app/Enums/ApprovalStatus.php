<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return Str::headline($this->name);
    }
}
