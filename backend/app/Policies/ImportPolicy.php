<?php

namespace App\Policies;

use App\Enums\ImportType;
use App\Enums\RoleName;
use App\Models\Import;
use App\Models\User;

/**
 * Importing needs `{type}.import`. An import belongs to its uploader; admins can see and run any.
 */
class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ImportType::Leads->permission()) || $user->can(ImportType::Clients->permission());
    }

    public function create(User $user, ImportType $type): bool
    {
        return $user->can($type->permission());
    }

    public function view(User $user, Import $import): bool
    {
        return $user->can($import->type->permission())
            && ($import->user_id === $user->id || $user->hasRole(RoleName::Admin->value));
    }

    public function start(User $user, Import $import): bool
    {
        return $this->view($user, $import);
    }
}
