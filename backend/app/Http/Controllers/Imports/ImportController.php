<?php

namespace App\Http\Controllers\Imports;

use App\Enums\ImportType;
use App\Enums\RoleName;
use App\Exports\CsvSanitizer;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Requests\Imports\StoreImportRequest;
use App\Http\Resources\Imports\ImportResource;
use App\Imports\ImportFiles;
use App\Imports\ImportSchema;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    /**
     * Own imports, newest first; admins see everyone's.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Import::class);

        /** @var User $user */
        $user = $request->user();
        $query = Import::query()->with('user')->latest('id');
        if (! $user->hasRole(RoleName::Admin->value)) {
            $query->where('user_id', $user->id);
        }

        return ImportResource::collection(ApiPagination::paginate($query, $request));
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$import, $sample] = ImportFiles::store($user, $request->importType(), $request->file('file'));

        $data = ImportResource::make($import->load('user'))->resolve($request);
        $data['sample_rows'] = $sample;
        $data['suggested_mapping'] = ImportSchema::suggestMapping($import->type, $import->headers);

        return response()->json(['data' => $data], Response::HTTP_CREATED);
    }

    public function show(Import $import): ImportResource
    {
        Gate::authorize('view', $import);

        return ImportResource::make($import->load('user'));
    }

    public function template(ImportType $type): Response
    {
        Gate::authorize('create', [Import::class, $type]);

        return response(ImportSchema::template($type), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$type->value.'-import-template.csv"',
        ]);
    }

    /**
     * The failed rows (at most the stored errors) as a CSV with an extra "Error" column. Built from the
     * row values stored with the errors: the uploaded file is deleted once the import finishes.
     */
    public function errors(Import $import): StreamedResponse
    {
        Gate::authorize('view', $import);

        /** @var list<array{row: int, message: string, values?: list<string|null>}> $errors */
        $errors = $import->errors ?? [];
        $headers = $import->headers;

        /** @var array<int, array{values: list<string|null>|null, messages: list<string>}> $rows */
        $rows = [];
        foreach ($errors as $error) {
            $rows[$error['row']] ??= ['values' => null, 'messages' => []];
            $rows[$error['row']]['values'] ??= $error['values'] ?? null;
            $rows[$error['row']]['messages'][] = $error['message'];
        }

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            CsvSanitizer::write($out, [...$headers, 'Error']);
            foreach ($rows as $row) {
                $values = array_pad(array_slice($row['values'] ?? [], 0, count($headers)), count($headers), '');
                CsvSanitizer::write($out, [...$values, implode('; ', $row['messages'])]);
            }
            fclose($out);
        }, 'import-'.$import->id.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
