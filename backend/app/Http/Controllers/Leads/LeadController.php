<?php

namespace App\Http\Controllers\Leads;

use App\Actions\Leads\CreateLead;
use App\Actions\Leads\DeleteLead;
use App\Actions\Leads\UpdateLead;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\LeadIndexQuery;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LeadController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Lead::class);

        return LeadResource::collection(ApiPagination::paginate(LeadIndexQuery::make($request), $request));
    }

    public function store(StoreLeadRequest $request, CreateLead $createLead): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lead = $createLead->handle($user, $request->leadAttributes(), $request->serviceIds() ?? [], $request->newClient());

        return LeadResource::make($this->loadForResponse($lead))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('view', $lead);

        return LeadResource::make($this->loadForResponse($lead));
    }

    /**
     * 200 with the lead (direct write) or 202 with the queued approval request.
     */
    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLead $updateLead): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updateLead->prepare($lead, $request->changes());
        $serviceIds = $request->serviceIds();

        return $this->updateOrRequestChange(
            $user,
            $lead,
            $changes,
            apply: fn () => LeadResource::make($this->loadForResponse($updateLead->handle($lead, $changes, $serviceIds))),
            relations: $serviceIds === null ? [] : ['services' => $serviceIds],
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, Lead $lead, DeleteLead $deleteLead): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $lead);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $lead,
            apply: function () use ($deleteLead, $lead): Response {
                $deleteLead->handle($lead);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    private function loadForResponse(Lead $lead): Lead
    {
        return $lead->load([...LeadResource::DEFAULT_WITH, 'client', 'owner', 'closer', 'platformAccount', 'services']);
    }
}
