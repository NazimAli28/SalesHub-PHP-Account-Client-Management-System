<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\ActivateUser;
use App\Actions\Users\DeactivateUser;
use App\Http\Controllers\Controller;
use App\Http\Queries\UserIndexQuery;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /api/users/{user}/deactivate and /activate. Both use the `deactivate` ability
 * (permission users.deactivate; never on yourself, never the last active admin).
 */
class UserActivationController extends Controller
{
    public function deactivate(Request $request, User $user, DeactivateUser $deactivateUser): UserResource
    {
        Gate::authorize('deactivate', $user);

        /** @var User $actor */
        $actor = $request->user();

        return UserResource::make($deactivateUser->handle($actor, $user)->load(UserIndexQuery::WITH));
    }

    public function activate(Request $request, User $user, ActivateUser $activateUser): UserResource
    {
        Gate::authorize('deactivate', $user);

        /** @var User $actor */
        $actor = $request->user();

        return UserResource::make($activateUser->handle($actor, $user)->load(UserIndexQuery::WITH));
    }
}
