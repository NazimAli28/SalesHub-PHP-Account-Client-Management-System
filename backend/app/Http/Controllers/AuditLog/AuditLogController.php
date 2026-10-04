<?php

namespace App\Http\Controllers\AuditLog;

use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\AuditLogIndexQuery;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Activity::class);

        return ActivityResource::collection(ApiPagination::paginate(AuditLogIndexQuery::make($request), $request));
    }
}
