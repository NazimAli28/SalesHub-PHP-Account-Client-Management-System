<?php

namespace App\Http\Controllers;

use App\Http\Requests\RevealSocialAccountCredentialsRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

class SocialAccountRevealController extends Controller
{
    public function __invoke(RevealSocialAccountCredentialsRequest $request, SocialAccount $socialAccount): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $fields = $request->fields();

        AuditLogger::credentialsRevealed($socialAccount, $user, $request, $fields);

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $socialAccount->getAttribute($field);
        }

        return response()->json(['data' => $values]);
    }
}
