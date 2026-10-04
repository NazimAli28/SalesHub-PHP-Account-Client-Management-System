<?php

namespace App\Http\Controllers\SocialAccounts;

use App\Actions\SocialAccounts\CreateSocialAccount;
use App\Actions\SocialAccounts\DeleteSocialAccount;
use App\Actions\SocialAccounts\UpdateSocialAccount;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\SocialAccountIndexQuery;
use App\Http\Requests\SocialAccounts\StoreSocialAccountRequest;
use App\Http\Requests\SocialAccounts\UpdateSocialAccountRequest;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class SocialAccountController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SocialAccount::class);

        return SocialAccountResource::collection(ApiPagination::paginate(SocialAccountIndexQuery::make($request), $request));
    }

    public function store(StoreSocialAccountRequest $request, CreateSocialAccount $createAccount): JsonResponse
    {
        $account = $createAccount->handle($request->accountAttributes());

        return SocialAccountResource::make($this->loadForResponse($account))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SocialAccount $socialAccount): SocialAccountResource
    {
        Gate::authorize('view', $socialAccount);

        return SocialAccountResource::make($this->loadForResponse($socialAccount));
    }

    /**
     * 200 with the account (direct write) or 202 with the queued approval request.
     */
    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount, UpdateSocialAccount $updateAccount): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();

        return $this->updateOrRequestChange(
            $user,
            $socialAccount,
            $request->changes(),
            apply: fn () => SocialAccountResource::make($this->loadForResponse($updateAccount->handle($socialAccount, $request->changes()))),
            reason: $request->reason(),
        );
    }

    /**
     * 204 (direct delete) or 202 with the queued approval request.
     */
    public function destroy(Request $request, SocialAccount $socialAccount, DeleteSocialAccount $deleteAccount): JsonResource|Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeChangeOrRequest($user, 'delete', $socialAccount);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->deleteOrRequestDeletion(
            $user,
            $socialAccount,
            apply: function () use ($deleteAccount, $socialAccount): Response {
                $deleteAccount->handle($socialAccount);

                return response()->noContent();
            },
            reason: $validated['reason'] ?? null,
        );
    }

    private function loadForResponse(SocialAccount $account): SocialAccount
    {
        return $account->load([...SocialAccountResource::DEFAULT_WITH, 'platformAccount']);
    }
}
