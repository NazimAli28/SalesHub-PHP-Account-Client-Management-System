<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuditLogger;
use App\Support\IpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureIpIsAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! IpAllowlist::permits($user, $request->ip())) {
            AuditLogger::auth('login_blocked_ip', $user, $request);

            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Sign-in is only allowed from the office network.',
                'code' => 'ip_not_allowed',
            ], 403);
        }

        return $next($request);
    }
}
