<?php

namespace App\Actions\Services;

use App\Models\Service;

class DeleteService
{
    /**
     * Soft delete; order items and lead links keep pointing at the row.
     */
    public function handle(Service $service): void
    {
        $service->delete();
    }
}
