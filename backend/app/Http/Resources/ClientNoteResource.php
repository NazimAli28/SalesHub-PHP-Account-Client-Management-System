<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\ClientNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientNote
 */
class ClientNoteResource extends JsonResource
{
    use FormatsApiValues;

    public const DEFAULT_WITH = ['author', 'client'];

    /**
     * `can_edit` / `can_delete` tell the UI which controls to show for the signed-in user.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'body' => $this->body,
            'is_pinned' => $this->is_pinned,
            'author' => UserSummaryResource::make($this->whenLoaded('author')),
            'can_edit' => $user !== null && $user->can('update', $this->resource),
            'can_delete' => $user !== null && $user->can('delete', $this->resource),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
