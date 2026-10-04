<?php

namespace App\Http\Resources\Imports;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Imports\ImportSchema;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes the stored file path. Row errors are left out of the list endpoint.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->enum($this->type),
            'status' => $this->enum($this->status),
            'original_filename' => $this->original_filename,
            'headers' => $this->headers,
            'mapping' => $this->mapping,
            'fields' => array_map(fn ($field) => $field->toArray(), ImportSchema::fields($this->type)),
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'created_rows' => $this->created_rows,
            'failed_rows' => $this->failed_rows,
            'errors' => $this->when($request->route()?->getName() !== 'imports.index', fn () => $this->errors ?? []),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'started_at' => $this->dateTime($this->started_at),
            'finished_at' => $this->dateTime($this->finished_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
