<?php

namespace App\Http\Requests\Approvals;

use App\Models\ApprovalRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return $approval instanceof ApprovalRequest && (bool) $this->user()?->can('review', $approval);
    }

    /**
     * A rejection always explains itself (data-model 6.4).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function comment(): string
    {
        return (string) $this->validated('comment');
    }
}
