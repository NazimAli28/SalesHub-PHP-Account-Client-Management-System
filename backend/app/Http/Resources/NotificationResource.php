<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * `type` is the short notification name (`ApprovalDecided`); `data` is the payload the class built.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'data' => $this->data,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->dateTime($this->read_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
