<?php

namespace App\Actions\Workstations;

use App\Models\Workstation;
use Illuminate\Validation\ValidationException;

class DeleteWorkstation
{
    /**
     * Soft delete. A workstation with seated users or assigned platform accounts must be cleared first.
     */
    public function handle(Workstation $workstation): void
    {
        if ($workstation->users()->exists() || $workstation->platformAccounts()->exists()) {
            throw ValidationException::withMessages([
                'workstation' => 'This workstation still has users or platform accounts. Reassign them first.',
            ]);
        }

        $workstation->delete();
    }
}
