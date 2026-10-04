<?php

namespace App\Models;

use App\Enums\Shift;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'floor', 'shift', 'team_lead_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, LogsModelActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'shift' => Shift::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Workstation, $this>
     */
    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    /**
     * "Unit 1 Alpha (Floor 3 Evening)".
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $shift = $this->shift->label();

            return trim("{$this->name} (Floor {$this->floor} {$shift})");
        });
    }
}
