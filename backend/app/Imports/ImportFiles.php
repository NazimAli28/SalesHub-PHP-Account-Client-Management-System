<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stores uploaded CSVs on the private disk and reads them back.
 */
final class ImportFiles
{
    public const DISK = 'local';

    public const MAX_ROWS = 2000;

    public const SAMPLE_ROWS = 5;

    /**
     * Validates the content, stores the file and creates the import.
     *
     * @return array{0: Import, 1: list<array<string, string>>} The import and its first sample rows.
     */
    public static function store(User $user, ImportType $type, UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8')) {
            self::reject('The file must be UTF-8 encoded.');
        }

        $csv = new CsvFile($file->getRealPath());
        if ($csv->headers() === []) {
            self::reject('The file is empty.');
        }

        $total = 0;
        $sample = [];
        foreach ($csv->rows() as $row) {
            $total++;
            if ($total > self::MAX_ROWS) {
                self::reject('The file has more than '.number_format(self::MAX_ROWS).' data rows. Split it and import the parts one by one.');
            }
            if ($total <= self::SAMPLE_ROWS) {
                $sample[] = $row;
            }
        }
        if ($total === 0) {
            self::reject('The file has a header row but no data rows.');
        }

        $path = Storage::disk(self::DISK)->putFile('imports', $file);

        $import = Import::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => (string) $path,
            'headers' => $csv->headers(),
            'status' => ImportStatus::Uploaded,
            'total_rows' => $total,
            'processed_rows' => 0,
            'created_rows' => 0,
            'failed_rows' => 0,
        ]);

        return [$import, $sample];
    }

    public static function open(Import $import): CsvFile
    {
        return new CsvFile(Storage::disk(self::DISK)->path($import->path));
    }

    /**
     * Deletes the uploaded CSV. Called when an import finishes (completed or failed) or is pruned;
     * the error report is built from the row values stored with the errors, not from the file.
     */
    public static function delete(Import $import): void
    {
        if ($import->path !== '') {
            Storage::disk(self::DISK)->delete($import->path);
        }
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
