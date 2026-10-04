<?php

namespace App\Policies;

class OrderPolicy extends ScopedResourcePolicy
{
    protected function resource(): string
    {
        return 'orders';
    }
}
