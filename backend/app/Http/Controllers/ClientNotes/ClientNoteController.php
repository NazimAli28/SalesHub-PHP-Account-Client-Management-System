<?php

namespace App\Http\Controllers\ClientNotes;

use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Requests\ClientNotes\StoreClientNoteRequest;
use App\Http\Requests\ClientNotes\UpdateClientNoteRequest;
use App\Http\Resources\ClientNoteResource;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes on a client (anyone who can view the client). Pinned notes come first, then newest first.
 *
 * GET    /api/clients/{client}/notes
 * POST   /api/clients/{client}/notes           body, is_pinned?
 * PATCH  /api/clients/{client}/notes/{note}    body (author only), is_pinned
 * DELETE /api/clients/{client}/notes/{note}    author or clients.update
 */
class ClientNoteController extends Controller
{
    public function index(Request $request, Client $client): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ClientNote::class, $client]);

        $query = ClientNote::query()->where('client_id', $client->id)->with(ClientNoteResource::DEFAULT_WITH)
            ->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id');

        return ClientNoteResource::collection(ApiPagination::paginate($query, $request));
    }

    public function store(StoreClientNoteRequest $request, Client $client): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $note = $client->notes()->create([
            'user_id' => $user->id,
            'body' => trim($request->string('body')->toString()),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        return ClientNoteResource::make($note->load(ClientNoteResource::DEFAULT_WITH))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateClientNoteRequest $request, Client $client, ClientNote $note): ClientNoteResource
    {
        $data = $request->validated();

        if (isset($data['body'])) {
            $data['body'] = trim((string) $data['body']);
        }

        $note->update($data);

        return ClientNoteResource::make($note->load(ClientNoteResource::DEFAULT_WITH));
    }

    public function destroy(Client $client, ClientNote $note): Response
    {
        Gate::authorize('delete', $note);

        $note->delete();

        return response()->noContent();
    }
}
