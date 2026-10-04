<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Typed access to the signed-in user inside rules().
 *
 * Every API route requires authentication, so the user is always set for real requests. The API docs
 * generator (Scramble) builds each Form Request bare to read its rules; a blank user lets those rules
 * evaluate so the request body still gets documented.
 */
trait ResolvesActor
{
    protected function actor(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : new User;
    }
}
