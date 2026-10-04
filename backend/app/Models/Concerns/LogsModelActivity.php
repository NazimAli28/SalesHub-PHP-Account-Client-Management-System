<?php

namespace App\Models\Concerns;

use App\Approvals\ApprovalContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Activity logging defaults shared by business models: fillable attributes only,
 * never hidden attributes (password hashes) or encrypted credential columns.
 */
trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('model')
            ->logOnly($this->loggableAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Called by the activity logger before saving an entry for this model. Changes applied from an
     * approved request carry its ID (`properties.approval_request_id`).
     */
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $approvalId = ApprovalContext::currentId();

        if ($approvalId === null) {
            return;
        }

        $properties = $activity->getAttribute('properties');
        $values = match (true) {
            $properties instanceof Collection => $properties->all(),
            is_array($properties) => $properties,
            default => [],
        };
        $values['approval_request_id'] = $approvalId;

        $activity->setAttribute('properties', collect($values));
    }

    /**
     * @return list<string>
     */
    protected function loggableAttributes(): array
    {
        $encrypted = array_keys(array_filter(
            $this->getCasts(),
            fn (mixed $cast): bool => $cast === 'encrypted',
        ));

        return array_values(array_diff($this->getFillable(), $this->getHidden(), $encrypted));
    }
}
