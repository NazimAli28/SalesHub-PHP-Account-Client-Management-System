<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;

/**
 * Notes follow the client: anyone who can view the client may read and add notes and pin them.
 * Only the author edits the text; the author or a user with `clients.update` deletes.
 */
class ClientNotePolicy
{
    public function viewAny(User $user, Client $client): bool
    {
        return $user->can('view', $client);
    }

    public function create(User $user, Client $client): bool
    {
        return $user->can('view', $client);
    }

    public function pin(User $user, ClientNote $note): bool
    {
        return $note->client !== null && $user->can('view', $note->client);
    }

    public function update(User $user, ClientNote $note): bool
    {
        return $note->user_id === $user->id && $this->pin($user, $note);
    }

    public function delete(User $user, ClientNote $note): bool
    {
        return $this->pin($user, $note)
            && ($note->user_id === $user->id || $user->can('clients.update'));
    }
}
