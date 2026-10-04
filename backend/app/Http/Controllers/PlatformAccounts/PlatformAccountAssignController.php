<?php

namespace App\Http\Controllers\PlatformAccounts;

use App\Actions\PlatformAccounts\UpdatePlatformAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformAccounts\AssignPlatformAccountRequest;
use App\Http\Resources\PlatformAccountResource;
use App\Models\PlatformAccount;

/**
 * PATCH /api/platform-accounts/{id}/assign (`platform-accounts.assign`, direct write only).
 * Body: `workstation_id` (integer, or null to unassign).
 */
class PlatformAccountAssignController extends Controller
{
    public function __invoke(AssignPlatformAccountRequest $request, PlatformAccount $platformAccount, UpdatePlatformAccount $updateAccount): PlatformAccountResource
    {
        $changes = $updateAccount->prepare($platformAccount, ['workstation_id' => $request->workstationId()]);
        $updateAccount->handle($platformAccount, $changes);

        return PlatformAccountResource::make(
            $platformAccount->load(PlatformAccountResource::DEFAULT_WITH)->loadCount('socialAccounts'),
        );
    }
}
