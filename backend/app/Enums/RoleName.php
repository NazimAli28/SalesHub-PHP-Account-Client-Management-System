<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum RoleName: string
{
    case Admin = 'admin';
    case Support = 'support';
    case TeamLead = 'team_lead';
    case SalesExecutive = 'sales_executive';

    public function label(): string
    {
        return Str::headline($this->name);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
