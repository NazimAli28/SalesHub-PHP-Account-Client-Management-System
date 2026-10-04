<?php

namespace App\Http\Controllers\Analytics;

use App\Analytics\OverviewReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\OverviewRequest;
use App\Http\Resources\Analytics\OverviewResource;

/**
 * GET /api/analytics/overview. Permissions: reports.view-all | view-team | view-own (the tier sets the row scope).
 */
class OverviewController extends Controller
{
    public function __invoke(OverviewRequest $request, OverviewReport $report): OverviewResource
    {
        return OverviewResource::make($report->build($request->scope(), $request->range()));
    }
}
