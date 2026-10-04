<?php

namespace App\Http\Controllers\ClientNotes;

use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Resources\ClientTimelineItemResource;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * GET /api/clients/{client}/timeline
 *
 * The client's notes merged with activity-log entries for the client and for its leads, orders and
 * payments that the user may see, newest first. Items:
 * `{id, kind: note|activity, at, actor: {id, name}|null, summary, link}`.
 */
class ClientTimelineController extends Controller
{
    /** Enum-backed workflow fields whose new value is safe and useful to show. */
    private const VALUE_FIELDS = ['stage', 'status'];

    public function __invoke(Request $request, Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        /** @var User $user */
        $user = $request->user();

        $size = ApiPagination::size($request);
        $number = ApiPagination::number($request);
        $window = $size * $number;

        $leadIds = $client->leads()->visibleTo($user)->withTrashed()->pluck('id')->all();
        $orders = $client->orders()->visibleTo($user)->withTrashed()->pluck('order_number', 'id');
        /** @var Collection<int, Payment> $payments */
        $payments = Payment::withTrashed()->whereIn('order_id', $orders->keys())->get(['id', 'order_id', 'sequence'])->keyBy('id');

        $activities = Activity::query()
            ->where(function (Builder $q) use ($client, $leadIds, $orders, $payments): void {
                $q->where(fn (Builder $s) => $s->where('subject_type', 'client')->where('subject_id', $client->id));

                foreach (['lead' => $leadIds, 'order' => $orders->keys()->all(), 'payment' => $payments->keys()->all()] as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn (Builder $s) => $s->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            });

        $notes = $client->notes();

        $total = (clone $activities)->count() + (clone $notes)->count();

        $entries = $activities->latest()->latest('id')->limit($window)->get()
            ->map(fn (Activity $a): array => $this->activityItem($a, $client, $orders, $payments))
            ->concat($notes->with('author')->latest()->latest('id')->limit($window)->get()
                ->map(fn (ClientNote $n): array => $this->noteItem($n, $client)));

        $causerIds = $entries->pluck('causer_id')->filter()->unique()->all();
        $users = User::query()->whereIn('id', $causerIds)->get(['id', 'name'])->keyBy('id');

        $items = $entries
            ->sortByDesc(fn (array $item): string => $item['at'].'|'.str_pad((string) $item['sort_id'], 12, '0', STR_PAD_LEFT))
            ->slice(($number - 1) * $size, $size)
            ->map(function (array $item) use ($users): array {
                $actor = $item['causer_id'] === null ? null : $users->get($item['causer_id']);
                unset($item['causer_id'], $item['sort_id']);
                $item['actor'] = $actor === null ? null : ['id' => $actor->id, 'name' => $actor->name];

                return $item;
            })
            ->values();

        $paginator = new LengthAwarePaginator($items, $total, $size, $number, [
            'path' => $request->url(),
            'pageName' => 'page[number]',
        ]);
        $paginator->appends('page[size]', (string) $size);

        return ClientTimelineItemResource::collection($paginator);
    }

    /**
     * @param  Collection<int, string>  $orders  order id => order number
     * @param  Collection<int, Payment>  $payments
     * @return array<string, mixed>
     */
    private function activityItem(Activity $activity, Client $client, Collection $orders, Collection $payments): array
    {
        $type = (string) $activity->subject_type;
        $id = (int) $activity->subject_id;

        $payment = $payments->has($id) ? $payments->get($id) : null;
        $paymentOrderId = $payment === null ? null : $payment->order_id;

        [$label, $link] = match ($type) {
            'lead' => ['Lead #'.$id, '/leads'],
            'order' => ['Order '.($orders->get($id) ?? '#'.$id), '/orders/'.$id],
            'payment' => [
                'Payment '.($payment === null ? '#'.$id : $payment->sequence).' of order '.($paymentOrderId === null ? '' : ($orders->get($paymentOrderId) ?? '')),
                '/orders/'.($paymentOrderId ?? ''),
            ],
            default => ['Client', '/clients/'.$client->id],
        };

        $event = $activity->event ?? $activity->description;
        $attributes = (array) ($activity->attribute_changes?->get('attributes') ?? []);
        $summary = trim(($type === 'client' ? 'Client' : $label).' '.$event);

        if ($event === 'updated' && $attributes !== []) {
            // Workflow fields show their new value ("stage → Quoted"); everything else only its name.
            $summary .= ': '.collect($attributes)
                ->map(fn (mixed $value, string $field): string => in_array($field, self::VALUE_FIELDS, true) && is_string($value)
                    ? Str::lower(Str::headline($field)).' → '.Str::headline($value)
                    : Str::lower(Str::headline($field)))
                ->implode(', ');
        }

        return [
            'id' => 'activity-'.$activity->id,
            'kind' => 'activity',
            'at' => $activity->created_at?->toIso8601ZuluString(),
            'summary' => $summary,
            'link' => $link,
            'causer_id' => $activity->causer_id,
            'sort_id' => $activity->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noteItem(ClientNote $note, Client $client): array
    {
        return [
            'id' => 'note-'.$note->id,
            'kind' => 'note',
            'at' => $note->created_at?->toIso8601ZuluString(),
            'summary' => 'Added a note: '.Str::limit($note->body, 140),
            'link' => '/clients/'.$client->id,
            'causer_id' => $note->user_id,
            'sort_id' => $note->id,
        ];
    }
}
