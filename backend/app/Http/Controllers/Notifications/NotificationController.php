<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The signed-in user's own database notifications (permission notifications.view).
 *
 * GET /api/notifications?filter[unread]=true|false   sort=-created_at (default) | created_at
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        /** @var QueryBuilder<DatabaseNotification> $query */
        $query = QueryBuilder::for($user->notifications()->getQuery(), $request)
            ->allowedFilters(AllowedFilter::callback('unread', function (Builder $query, mixed $value): void {
                filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNull('read_at')
                    : $query->whereNotNull('read_at');
            }))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at');

        return NotificationResource::collection(ApiPagination::paginate($query, $request));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->user($request)->unreadNotifications()->count()]]);
    }

    /**
     * The envelope is built by hand: a resource with its own `data` key (the notification payload)
     * is not wrapped in `data` by Laravel.
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);

        $record = DatabaseNotification::query()->findOrFail($notification);
        abort_unless($record->notifiable_type === $user->getMorphClass() && (int) $record->notifiable_id === $user->id, 403, 'This action is unauthorized.');

        $record->markAsRead();

        return response()->json(['data' => NotificationResource::make($record->refresh())->resolve($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can('notifications.view'), 403, 'This action is unauthorized.');

        return $user;
    }
}
