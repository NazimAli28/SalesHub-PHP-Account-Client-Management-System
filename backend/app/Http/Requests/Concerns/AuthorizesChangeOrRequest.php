<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For Form Requests of update/delete endpoints that follow the update-vs-queue rule: the user passes
 * authorization when they may write directly OR request the change. The controller then picks the path.
 */
trait AuthorizesChangeOrRequest
{
    /**
     * @param  'update'|'delete'  $ability
     */
    protected function canChangeOrRequest(string $ability, mixed $record): bool
    {
        $user = $this->user();

        if ($user === null || ! $record instanceof Model) {
            return false;
        }

        return $user->can($ability, $record) || $user->can('requestChange', $record);
    }
}
