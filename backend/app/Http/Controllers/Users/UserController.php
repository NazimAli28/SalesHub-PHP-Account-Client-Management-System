<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\UserIndexQuery;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return UserResource::collection(ApiPagination::paginate(UserIndexQuery::make($request), $request));
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $user = $createUser->handle($actor, $request->userAttributes(), $request->role());

        return UserResource::make($user->load(UserIndexQuery::WITH))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->load(UserIndexQuery::WITH));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $updateUser->handle($actor, $user, $request->changes(), $request->role());

        return UserResource::make($user->refresh()->load(UserIndexQuery::WITH));
    }

    public function destroy(Request $request, User $user, DeleteUser $deleteUser): Response
    {
        Gate::authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();
        $deleteUser->handle($actor, $user);

        return response()->noContent();
    }
}
