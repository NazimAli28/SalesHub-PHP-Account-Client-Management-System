<?php

namespace App\Actions\Auth;

use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The last step of every successful sign-in (password only, or password + second factor).
 */
final class CompleteLogin
{
    /**
     * @param  array<string, mixed>  $auditExtra
     */
    public function handle(Request $request, User $user, bool $remember, array $auditExtra = []): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();

        AuditLogger::auth('login', $user, $request, $auditExtra);

        return (new MeResource($user->load(['team', 'workstation', 'roles'])))->response()->setStatusCode(200);
    }
}
