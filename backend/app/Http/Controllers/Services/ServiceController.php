<?php

namespace App\Http\Controllers\Services;

use App\Actions\Services\CreateService;
use App\Actions\Services\DeleteService;
use App\Actions\Services\UpdateService;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\ServiceIndexQuery;
use App\Http\Requests\Services\StoreServiceRequest;
use App\Http\Requests\Services\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Service::class);

        return ServiceResource::collection(ApiPagination::paginate(ServiceIndexQuery::make($request), $request));
    }

    public function store(StoreServiceRequest $request, CreateService $createService): JsonResponse
    {
        $service = $createService->handle($request->serviceAttributes());

        return ServiceResource::make($service)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Service $service): ServiceResource
    {
        Gate::authorize('view', $service);

        return ServiceResource::make($service);
    }

    public function update(UpdateServiceRequest $request, Service $service, UpdateService $updateService): ServiceResource
    {
        return ServiceResource::make($updateService->handle($service, $request->changes())->refresh());
    }

    public function destroy(Service $service, DeleteService $deleteService): Response
    {
        Gate::authorize('delete', $service);

        $deleteService->handle($service);

        return response()->noContent();
    }
}
