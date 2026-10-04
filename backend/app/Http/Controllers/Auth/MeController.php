<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401, 'Unauthenticated.');

        // Explicit 200: a resource of a just-created model would otherwise answer 201.
        return (new MeResource($user->load(['team', 'workstation', 'roles'])))
            ->response()
            ->setStatusCode(200);
    }
}
