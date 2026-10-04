<?php

namespace App\Actions\Workstations;

use App\Models\Workstation;

class UpdateWorkstation
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(Workstation $workstation, array $changes): Workstation
    {
        $workstation->fill($changes)->save();

        return $workstation;
    }
}
