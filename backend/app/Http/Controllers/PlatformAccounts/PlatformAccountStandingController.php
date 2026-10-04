<?php

namespace App\Http\Controllers\PlatformAccounts;

use App\Actions\Approvals\SubmitChangeRequest;
use App\Actions\PlatformAccounts\UpdatePlatformAccount;
use App\Http\Controllers\Concerns\RoutesChangesThroughApprovals;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformAccounts\ChangePlatformAccountStandingRequest;
use App\Http\Resources\PlatformAccountResource;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH /api/platform-accounts/{id}/standing. With `platform-accounts.change-standing` the standing is set
 * directly (200, `standing_changed_at` updated); otherwise users with `request-change` queue it (202).
 * The queued payload holds only `standing`; the applier stamps `standing_changed_at` on approval.
 */
class PlatformAccountStandingController extends Controller
{
    use RoutesChangesThroughApprovals;

    public function __invoke(
        ChangePlatformAccountStandingRequest $request,
        PlatformAccount $platformAccount,
        UpdatePlatformAccount $updateAccount,
        SubmitChangeRequest $submit,
    ): JsonResource|Response {
        /** @var User $user */
        $user = $request->user();

        if ($user->can('changeStanding', $platformAccount)) {
            $changes = $updateAccount->prepare($platformAccount, ['standing' => $request->standing()]);
            $updateAccount->handle($platformAccount, $changes);

            return PlatformAccountResource::make(
                $platformAccount->load(PlatformAccountResource::DEFAULT_WITH)->loadCount('socialAccounts'),
            );
        }

        if ($user->can('requestChange', $platformAccount)) {
            return $this->queued($submit->update($user, $platformAccount, ['standing' => $request->standing()], reason: $request->reason()));
        }

        throw new AuthorizationException;
    }
}
