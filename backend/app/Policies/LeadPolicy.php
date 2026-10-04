<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy extends ScopedResourcePolicy
{
    protected function resource(): string
    {
        return 'leads';
    }

    public function reassign(User $user, Lead $lead): bool
    {
        return $this->canOn($user, 'leads.reassign', $lead);
    }
}
