<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteTeam;
use App\Actions\Teams\UpdateTeam;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\TeamIndexQuery;
use App\Http\Requests\Teams\StoreTeamRequest;
use App\Http\Requests\Teams\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        return TeamResource::collection(ApiPagination::paginate(TeamIndexQuery::make($request), $request));
    }

    public function store(StoreTeamRequest $request, CreateTeam $createTeam): JsonResponse
    {
        $team = $createTeam->handle($request->teamAttributes());

        return TeamResource::make($this->loadForResponse($team))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Team $team): TeamResource
    {
        Gate::authorize('view', $team);

        return TeamResource::make($this->loadForResponse($team));
    }

    public function update(UpdateTeamRequest $request, Team $team, UpdateTeam $updateTeam): TeamResource
    {
        $updateTeam->handle($team, $request->changes());

        return TeamResource::make($this->loadForResponse($team));
    }

    public function destroy(Team $team, DeleteTeam $deleteTeam): Response
    {
        Gate::authorize('delete', $team);

        $deleteTeam->handle($team);

        return response()->noContent();
    }

    /**
     * The detail response always lists the members and carries the counts.
     */
    private function loadForResponse(Team $team): Team
    {
        return $team->refresh()->load([...TeamResource::DEFAULT_WITH, ...TeamResource::INCLUDES])->loadCount(['members', 'workstations']);
    }
}
