<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Imports\ImportFiles;
use App\Imports\RowImporter;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates the records of a started import row by row (through the same actions as the UI), keeps the
 * progress counters up to date and records up to {@see Import::MAX_STORED_ERRORS} row errors.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    private const PROGRESS_EVERY = 20;

    public function __construct(public readonly int $importId) {}

    public function handle(): void
    {
        $import = Import::query()->with('user')->find($this->importId);
        if ($import === null || $import->status !== ImportStatus::Queued || $import->user === null) {
            return;
        }

        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();

        /** @var array<string, string|null> $mapping */
        $mapping = $import->mapping ?? [];
        $columns = array_filter($mapping);
        $importer = new RowImporter($import->type, $import->user);

        $processed = $created = $failed = 0;
        /** @var list<array{row: int, column: string|null, field: string|null, message: string}> $errors */
        $errors = [];

        foreach (ImportFiles::open($import)->rows() as $number => $row) {
            $processed++;

            try {
                $rowErrors = $importer->create(RowImporter::values($row, $mapping));
            } catch (Throwable $e) {
                report($e);
                $rowErrors = ['' => ['This row could not be saved.']];
            }

            if ($rowErrors === []) {
                $created++;
            } else {
                $failed++;
                foreach ($rowErrors as $field => $messages) {
                    foreach ($messages as $message) {
                        if (count($errors) >= Import::MAX_STORED_ERRORS) {
                            break 2;
                        }
                        $header = array_search($field, $columns, true);
                        $errors[] = [
                            'row' => $number,
                            'column' => $header === false ? null : (string) $header,
                            'field' => $field === '' ? null : $field,
                            'message' => $message,
                        ];
                    }
                }
            }

            if ($processed % self::PROGRESS_EVERY === 0) {
                $import->forceFill(['processed_rows' => $processed, 'created_rows' => $created, 'failed_rows' => $failed, 'errors' => $errors])->save();
            }
        }

        $import->forceFill([
            'status' => ImportStatus::Completed,
            'processed_rows' => $processed,
            'created_rows' => $created,
            'failed_rows' => $failed,
            'errors' => $errors,
            'finished_at' => now(),
        ])->save();

        activity('import')
            ->event('completed')
            ->causedBy($import->user)
            ->withProperties(['import_id' => $import->id, 'type' => $import->type->value, 'rows' => $processed, 'created' => $created, 'failed' => $failed])
            ->log('import_completed');
    }

    public function failed(Throwable $exception): void
    {
        Import::query()->whereKey($this->importId)->update([
            'status' => ImportStatus::Failed->value,
            'finished_at' => now(),
        ]);

        report($exception);
    }
}
