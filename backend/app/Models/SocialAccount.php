<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVisibilityScope;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['platform_account_id', 'platform', 'username', 'login_email', 'password', 'created_on', 'is_in_use'])]
#[Hidden(['password'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasApprovals, HasFactory, HasVisibilityScope, LogsModelActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'platform' => SocialPlatform::class,
            'created_on' => 'date:Y-m-d',
            'is_in_use' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PlatformAccount, $this>
     */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    protected static function visibilityResource(): string
    {
        return 'social-accounts';
    }

    protected function applyTeamVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('platformAccount', fn (Builder $q) => $q->visibleTo($user));
    }

    protected function applyOwnVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('platformAccount', fn (Builder $q) => $q->visibleTo($user));
    }
}
