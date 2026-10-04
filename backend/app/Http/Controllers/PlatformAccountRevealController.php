<?php

namespace App\Http\Controllers;

use App\Http\Requests\RevealPlatformAccountCredentialsRequest;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

class PlatformAccountRevealController extends Controller
{
    public function __invoke(RevealPlatformAccountCredentialsRequest $request, PlatformAccount $platformAccount): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $fields = $request->fields();

        AuditLogger::credentialsRevealed($platformAccount, $user, $request, $fields);

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $platformAccount->getAttribute($field);
        }

        return response()->json(['data' => $values]);
    }
}
