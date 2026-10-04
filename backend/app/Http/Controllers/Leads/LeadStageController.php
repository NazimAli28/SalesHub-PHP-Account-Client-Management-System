<?php

namespace App\Http\Controllers\Leads;

use App\Actions\Leads\UpdateLead;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\MoveLeadStageRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH /api/leads/{lead}/stage (Kanban move). Same update-vs-queue rule as a normal update.
 */
class LeadStageController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function __invoke(MoveLeadStageRequest $request, Lead $lead, UpdateLead $updateLead): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updateLead->prepare($lead, $request->changes());

        return $this->updateOrRequestChange(
            $user,
            $lead,
            $changes,
            apply: fn () => LeadResource::make($updateLead->handle($lead, $changes)->load(LeadResource::DEFAULT_WITH)),
            reason: $request->reason(),
        );
    }
}
