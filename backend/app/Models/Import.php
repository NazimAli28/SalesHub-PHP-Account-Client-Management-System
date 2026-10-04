<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CSV import (leads or clients). Not activity-logged: progress writes are frequent and the
 * start and finish are audited explicitly (subject-less) by the import actions.
 */
#[Fillable([
    'user_id', 'type', 'status', 'original_filename', 'path', 'headers', 'mapping', 'total_rows',
    'processed_rows', 'created_rows', 'failed_rows', 'errors', 'started_at', 'finished_at',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    /** Row errors kept on the import. */
    public const MAX_STORED_ERRORS = 200;

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
