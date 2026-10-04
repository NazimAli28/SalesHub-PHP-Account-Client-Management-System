<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Never exposes password, remember_token or other hidden columns.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_url' => $this->avatar_path ? Storage::url($this->avatar_path) : null,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'roles' => $this->getRoleNames()->values()->all(),
            'team' => $this->team === null ? null : [
                'id' => $this->team->id,
                'name' => $this->team->name,
                'floor' => $this->team->floor,
                'shift' => $this->team->shift->value,
            ],
            'workstation' => $this->workstation === null ? null : [
                'id' => $this->workstation->id,
                'code' => $this->workstation->code,
            ],
        ];
    }
}
