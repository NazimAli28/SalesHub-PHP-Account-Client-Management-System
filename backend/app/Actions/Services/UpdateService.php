<?php

namespace App\Actions\Services;

use App\Models\Service;

class UpdateService
{
    /**
     * Catalog price changes never alter existing order items (they store their own snapshot).
     *
     * @param  array<string, mixed>  $changes
     */
    public function handle(Service $service, array $changes): Service
    {
        $service->fill($changes)->save();

        return $service;
    }
}
