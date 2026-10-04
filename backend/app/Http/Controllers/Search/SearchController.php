<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Search\RecordSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/search?q=term (2 to 100 characters, 60 requests per minute)
 *
 * `data` is a list of groups `{key, label, hits: [{type, id, title, subtitle, url}]}` with up to 5 hits
 * each; groups the user may not view are left out.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, RecordSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $search->search($user, trim($validated['q']))]);
    }
}
