<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workstation;

class WorkstationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workstations.view');
    }

    public function view(User $user, Workstation $workstation): bool
    {
        return $user->can('workstations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('workstations.manage');
    }

    public function update(User $user, Workstation $workstation): bool
    {
        return $user->can('workstations.manage');
    }

    public function delete(User $user, Workstation $workstation): bool
    {
        return $user->can('workstations.manage');
    }
}
