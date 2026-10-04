<?php

namespace App\Actions\Workstations;

use App\Models\Workstation;

class CreateWorkstation
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Workstation
    {
        return Workstation::query()->create($attributes);
    }
}
