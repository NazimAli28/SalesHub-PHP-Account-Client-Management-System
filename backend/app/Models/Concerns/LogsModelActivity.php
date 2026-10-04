<?php

namespace App\Models\Concerns;

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
