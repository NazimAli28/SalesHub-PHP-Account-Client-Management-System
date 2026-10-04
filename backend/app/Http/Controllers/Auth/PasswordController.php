<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function __invoke(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'remember_token' => Str::random(60),
        ])->save();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $request->session()->regenerate();

        AuditLogger::auth('password_changed', $user, $request);

        return response()->noContent();
    }
}
