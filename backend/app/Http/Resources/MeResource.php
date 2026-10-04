<?php

namespace App\Http\Resources;

use App\Actions\Auth\EnsureNotDemoMode;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user: UserResource plus the effective permission names (used by the SPA to show or hide UI only).
 *
 * @mixin User
 */
class MeResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            // Public demo: shared accounts, so password changes and 2FA are switched off.
            'demo_mode' => EnsureNotDemoMode::enabled(),
        ];
    }
}
