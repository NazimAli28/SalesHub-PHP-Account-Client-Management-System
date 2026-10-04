<?php

namespace App\Http\Controllers\Leads;

use App\Actions\Leads\UpdateLead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ReassignLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;

/**
 * PATCH /api/leads/{lead}/owner (`leads.reassign`, direct write only).
 */
class LeadOwnerController extends Controller
{
    public function __invoke(ReassignLeadRequest $request, Lead $lead, UpdateLead $updateLead): LeadResource
    {
        $updateLead->handle($lead, ['owner_id' => $request->ownerId()]);

        return LeadResource::make($lead->load([...LeadResource::DEFAULT_WITH, 'owner']));
    }
}
