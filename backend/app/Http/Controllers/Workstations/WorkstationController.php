<?php

namespace App\Http\Controllers\Workstations;

use App\Actions\Workstations\CreateWorkstation;
use App\Actions\Workstations\DeleteWorkstation;
use App\Actions\Workstations\UpdateWorkstation;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\WorkstationIndexQuery;
use App\Http\Requests\Workstations\StoreWorkstationRequest;
use App\Http\Requests\Workstations\UpdateWorkstationRequest;
use App\Http\Resources\WorkstationResource;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WorkstationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workstation::class);

        return WorkstationResource::collection(ApiPagination::paginate(WorkstationIndexQuery::make($request), $request));
    }

    public function store(StoreWorkstationRequest $request, CreateWorkstation $createWorkstation): JsonResponse
    {
        $workstation = $createWorkstation->handle($request->workstationAttributes());

        return WorkstationResource::make($this->loadForResponse($workstation))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Workstation $workstation): WorkstationResource
    {
        Gate::authorize('view', $workstation);

        return WorkstationResource::make($this->loadForResponse($workstation));
    }

    public function update(UpdateWorkstationRequest $request, Workstation $workstation, UpdateWorkstation $updateWorkstation): WorkstationResource
    {
        $updateWorkstation->handle($workstation, $request->changes());

        return WorkstationResource::make($this->loadForResponse($workstation));
    }

    public function destroy(Workstation $workstation, DeleteWorkstation $deleteWorkstation): Response
    {
        Gate::authorize('delete', $workstation);

        $deleteWorkstation->handle($workstation);

        return response()->noContent();
    }

    private function loadForResponse(Workstation $workstation): Workstation
    {
        return $workstation->refresh()
            ->load([...WorkstationResource::DEFAULT_WITH, ...WorkstationResource::INCLUDES])
            ->loadCount(['users', 'platformAccounts']);
    }
}
