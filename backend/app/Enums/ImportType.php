<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ImportType: string
{
    case Leads = 'leads';
    case Clients = 'clients';

    public function label(): string
    {
        return Str::headline($this->name);
    }

    /**
     * Permission needed to import this type.
     */
    public function permission(): string
    {
        return $this->value.'.import';
    }
}
