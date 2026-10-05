<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Imports\ImportFiles;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CSV import (leads or clients). Not activity-logged: progress writes are frequent and the
 * start and finish are audited explicitly (subject-less) by the import actions.
 *
 * @property list<string> $headers
 */
#[Fillable([
    'user_id', 'type', 'status', 'original_filename', 'path', 'headers', 'mapping', 'total_rows',
    'processed_rows', 'created_rows', 'failed_rows', 'errors', 'started_at', 'finished_at',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    use Prunable;

    /** Row errors kept on the import. */
    public const MAX_STORED_ERRORS = 200;

    /**
     * `model:prune` (daily): uploads that were never started, older than
     * `saleshub.imports.prune_unstarted_after_hours`. Their CSV is deleted with them.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $hours = max(1, (int) config('saleshub.imports.prune_unstarted_after_hours', 24));

        return static::query()
            ->where('status', ImportStatus::Uploaded->value)
            ->where('created_at', '<=', now()->subHours($hours));
    }

    protected function pruning(): void
    {
        ImportFiles::delete($this);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'errors' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
