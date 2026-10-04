<?php

namespace App\Http\Controllers\Imports;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Imports\ImportResource;
use App\Imports\ImportMapping;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/imports/{import}/start: saves the mapping, queues the job and answers 202 with the import.
 */
class ImportStartController extends Controller
{
    public function __invoke(Request $request, Import $import): Response
    {
        Gate::authorize('start', $import);
        abort_unless($import->status === ImportStatus::Uploaded, 409, 'This import has already been started.');

        /** @var User $user */
        $user = $request->user();
        $import->forceFill([
            'mapping' => ImportMapping::validate($import, $request->input('mapping')),
            'status' => ImportStatus::Queued,
        ])->save();

        activity('import')
            ->event('started')
            ->causedBy($user)
            ->withProperties(['import_id' => $import->id, 'type' => $import->type->value, 'rows' => $import->total_rows])
            ->log('import_started');

        ProcessImport::dispatch($import->id);

        return ImportResource::make($import->refresh()->load('user'))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
