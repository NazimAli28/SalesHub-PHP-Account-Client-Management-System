<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ImportType;
use App\Exports\CsvExport;
use App\Exports\CsvSanitizer;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/exports/{type}: CSV of the rows the user can see, filtered and sorted like the index endpoint.
 */
class ExportController extends Controller
{
    public const MAX_ROWS = 10000;

    private const CHUNK = 500;

    public function __invoke(Request $request, ImportType $type): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can('reports.export'), 403);
        Gate::authorize('viewAny', $type === ImportType::Leads ? Lead::class : Client::class);

        $export = CsvExport::for($type, $request);
        $total = min($export->query->count(), self::MAX_ROWS);

        activity('export')
            ->event('exported')
            ->causedBy($user)
            ->withProperties([
                'type' => $type->value,
                'rows' => $total,
                'filters' => $request->query('filter', []),
                'sort' => $request->query('sort'),
            ])
            ->log('export_'.$type->value);

        return response()->streamDownload(function () use ($export, $total): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            CsvSanitizer::write($out, $export->headings);

            // A unique tie-breaker keeps offset chunks stable when the requested sort has ties.
            $export->query->orderBy($export->query->getModel()->getQualifiedKeyName());

            $written = 0;
            for ($page = 1; $written < $total; $page++) {
                $models = $export->query->forPage($page, self::CHUNK)->get();
                if ($models->isEmpty()) {
                    break;
                }
                foreach ($models as $model) {
                    if ($written >= $total) {
                        break 2;
                    }
                    CsvSanitizer::write($out, $export->row($model));
                    $written++;
                }
            }

            fclose($out);
        }, $type->value.'-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
