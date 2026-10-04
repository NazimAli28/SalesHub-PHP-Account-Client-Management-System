<?php

namespace App\Http\Requests\Leads;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change a lead's owner (`leads.reassign`; direct only, never queued).
 */
class ReassignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead && (bool) $this->user()?->can('reassign', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'owner_id' => [
                'required',
                'integer',
                new VisibleTo(User::class, $user, fn (Builder $q) => $q
                    ->where('is_active', true)
                    ->whereHas('roles', fn (Builder $r) => $r->whereIn('name', [RoleName::SalesExecutive->value, RoleName::TeamLead->value]))),
            ],
        ];
    }

    public function ownerId(): int
    {
        return (int) $this->validated('owner_id');
    }
}
