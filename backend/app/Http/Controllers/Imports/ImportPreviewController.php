<?php

namespace App\Http\Controllers\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Http\Controllers\Controller;
use App\Imports\ImportFiles;
use App\Imports\ImportMapping;
use App\Imports\RowImporter;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/imports/{import}/preview: validates the first rows with the real create rules, writes nothing.
 */
class ImportPreviewController extends Controller
{
    public const ROWS = 50;

    public function __invoke(Request $request, Import $import): JsonResponse
    {
        Gate::authorize('view', $import);
        abort_unless($import->status === ImportStatus::Uploaded, 409, 'This import has already been started.');

        /** @var User $user */
        $user = $request->user();
        $mapping = ImportMapping::validate($import, $request->input('mapping'));
        $importer = new RowImporter($import->type, $user);

        $rows = [];
        $seen = [];
        $valid = 0;

        foreach (ImportFiles::open($import)->rows() as $number => $row) {
            if (count($rows) >= self::ROWS) {
                break;
            }

            $values = RowImporter::values($row, $mapping);
            $errors = $importer->check($values);

            // A client's Discord username must be unique, also inside the file itself.
            if ($import->type === ImportType::Clients && isset($values['discord_username'])) {
                $key = strtolower($values['discord_username']);
                if (isset($seen[$key]) && ! isset($errors['discord_username'])) {
                    $errors['discord_username'] = ['Duplicate of row '.$seen[$key].' in this file.'];
                }
                $seen[$key] ??= $number;
            }

            $valid += $errors === [] ? 1 : 0;
            $rows[] = ['row' => $number, 'values' => $row, 'valid' => $errors === [], 'errors' => (object) $errors];
        }

        return response()->json(['data' => [
            'rows' => $rows,
            'summary' => [
                'total_rows' => $import->total_rows,
                'checked' => count($rows),
                'valid' => $valid,
                'invalid' => count($rows) - $valid,
            ],
        ]]);
    }
}
