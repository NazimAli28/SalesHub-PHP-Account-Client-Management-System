<?php

namespace App\Actions\Leads;

use App\Models\Lead;

class DeleteLead
{
    /**
     * Soft delete; the lead_service rows stay so a restore brings the services back.
     */
    public function handle(Lead $lead): void
    {
        $lead->delete();
    }
}
