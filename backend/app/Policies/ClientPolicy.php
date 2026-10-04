<?php

namespace App\Policies;

class ClientPolicy extends ScopedResourcePolicy
{
    protected function resource(): string
    {
        return 'clients';
    }
}
