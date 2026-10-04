<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * One audit-log entry. `properties` and `attribute_changes` are scrubbed: any key that looks like a
 * secret is replaced with "[redacted]", whatever the writer logged.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    use FormatsApiValues;

    private const SECRET_KEY = '/pass(word|wd)|secret|token|credential|api[_-]?key|recovery|phone|remember|authorization|cookie|payload/i';

    /** Attributes tried, in order, to give a subject a readable label. */
    private const LABEL_ATTRIBUTES = ['name', 'username', 'code', 'order_number', 'discord_username', 'slug', 'title'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $causer = $this->causer;

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'causer_id' => $this->causer_id,
            'causer' => $causer instanceof User ? UserSummaryResource::make($causer) : null,
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subjectLabel($this->subject),
            ],
            'properties' => self::scrub($this->properties?->all() ?? []),
            'attribute_changes' => self::scrub($this->attribute_changes?->all() ?? []),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }

    private function subjectLabel(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (self::LABEL_ATTRIBUTES as $attribute) {
            $value = $subject->getAttributes()[$attribute] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public static function scrub(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $clean[$key] = '[redacted]';
            } else {
                $clean[$key] = is_array($value) ? self::scrub($value) : $value;
            }
        }

        return $clean;
    }
}
