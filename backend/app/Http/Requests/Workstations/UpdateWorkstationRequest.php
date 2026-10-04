<?php

namespace App\Http\Requests\Workstations;

use App\Http\Requests\Workstations\Concerns\WorkstationRules;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWorkstationRequest extends FormRequest
{
    use WorkstationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->workstation());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->workstationAttributeRules($this->workstation());

        return [
            'code' => ['sometimes', 'required', ...$rules['code']],
            'team_id' => ['sometimes', 'required', ...$rules['team_id']],
            'label' => ['sometimes', ...$rules['label']],
            'is_active' => ['sometimes', ...$rules['is_active']],
        ];
    }

    /**
     * Users seated on a workstation must stay on the workstation's team.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $teamId = $this->input('team_id');

            if ($teamId === null || $validator->errors()->has('team_id') || (int) $teamId === $this->workstation()->team_id) {
                return;
            }

            if ($this->workstation()->users()->exists()) {
                $validator->errors()->add('team_id', 'Users are seated on this workstation. Move them first.');
            }
        }];
    }

    public function workstation(): Workstation
    {
        /** @var Workstation $workstation */
        $workstation = $this->route('workstation');

        return $workstation;
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->validated();
    }
}
