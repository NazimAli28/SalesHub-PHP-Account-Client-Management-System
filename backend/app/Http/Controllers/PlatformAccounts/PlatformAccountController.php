<?php

namespace App\Http\Controllers\PlatformAccounts;

use App\Actions\PlatformAccounts\CreatePlatformAccount;
use App\Actions\PlatformAccounts\DeletePlatformAccount;
use App\Actions\PlatformAccounts\UpdatePlatformAccount;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\PlatformAccountIndexQuery;
use App\Http\Requests\PlatformAccounts\StorePlatformAccountRequest;
use App\Http\Requests\PlatformAccounts\UpdatePlatformAccountRequest;
use App\Http\Resources\PlatformAccountResource;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PlatformAccountController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PlatformAccount::class);

        return PlatformAccountResource::collection(ApiPagination::paginate(PlatformAccountIndexQuery::make($request), $request));
    }

    public function store(StorePlatformAccountRequest $request, CreatePlatformAccount $createAccount): JsonResponse
    {
        $account = $createAccount->handle($request->accountAttributes());

        return PlatformAccountResource::make($this->loadForResponse($account))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PlatformAccount $platformAccount): PlatformAccountResource
    {
        Gate::authorize('view', $platformAccount);

        return PlatformAccountResource::make($this->loadForResponse($platformAccount));
    }

    /**
     * 200 with the account (direct write) or 202 with the queued approval request.
     */
    public function update(UpdatePlatformAccountRequest $request, PlatformAccount $platformAccount, UpdatePlatformAccount $updateAccount): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $updateAccount->prepare($platformAccount, $request->changes());

        return $this->updateOrRequestChange(
            $user,
            $platformAccount,
            $changes,
            apply: fn () => PlatformAccountResource::make($this->loadForResponse($updateAccount->handle($platformAccount, $changes))),
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, PlatformAccount $platformAccount, DeletePlatformAccount $deleteAccount): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $platformAccount);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $platformAccount,
            apply: function () use ($deleteAccount, $platformAccount): Response {
                $deleteAccount->handle($platformAccount);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    private function loadForResponse(PlatformAccount $account): PlatformAccount
    {
        return $account->load(PlatformAccountResource::DEFAULT_WITH)->loadCount('socialAccounts');
    }
}
