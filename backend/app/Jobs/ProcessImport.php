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
 * progress counters up to date and records up to {@see Import::MAX_STORED_ERRORS} row errors. The first
 * error of a failed row also keeps the row's values, so the error report works after the uploaded file
 * is deleted (when the import completes or fails).
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
        // Claim the import atomically: a duplicate job (or a retry) finds it no longer queued.
        $claimed = Import::query()
            ->whereKey($this->importId)
            ->where('status', ImportStatus::Queued->value)
            ->update(['status' => ImportStatus::Processing->value, 'started_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $import = Import::query()->with('user')->findOrFail($this->importId);
        $user = $import->user;

        if ($user === null) {
            $this->finish($import, ImportStatus::Failed);

            return;
        }

        /** @var array<string, string|null> $mapping */
        $mapping = $import->mapping ?? [];
        $columns = array_filter($mapping);
        $importer = new RowImporter($import->type, $user);

        $processed = $created = $failed = 0;
        /** @var list<array<string, mixed>> $errors */
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
                $first = true;
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
                            ...($first ? ['values' => array_values($row)] : []),
                        ];
                        $first = false;
                    }
                }
            }

            if ($processed % self::PROGRESS_EVERY === 0) {
                $import->forceFill(['processed_rows' => $processed, 'created_rows' => $created, 'failed_rows' => $failed, 'errors' => $errors])->save();
            }
        }

        $import->forceFill([
            'processed_rows' => $processed,
            'created_rows' => $created,
            'failed_rows' => $failed,
            'errors' => $errors,
        ]);
        $this->finish($import, ImportStatus::Completed);

        activity('import')
            ->event('completed')
            ->causedBy($import->user)
            ->withProperties(['import_id' => $import->id, 'type' => $import->type->value, 'rows' => $processed, 'created' => $created, 'failed' => $failed])
            ->log('import_completed');
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::query()->find($this->importId);

        if ($import !== null) {
            $this->finish($import, ImportStatus::Failed);
        }

        report($exception);
    }

    /**
     * Marks the import finished and deletes the uploaded file: nothing reads it afterwards.
     */
    private function finish(Import $import, ImportStatus $status): void
    {
        $import->forceFill(['status' => $status, 'finished_at' => now()])->save();
        ImportFiles::delete($import);
    }
}
