<?php

namespace App\Actions\Leads;

use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for lead changes: used by the controller (direct update) and by LeadApplier
 * (approved request), so both produce identical results.
 */
class UpdateLead
{
    /**
     * Turns validated input into the full change set, applying the pipeline rules (data-model 3.1):
     * leaving `lost` clears lost_reason/lost_note; `won` and `lost` clear next_follow_up_on.
     * Pure: does not touch the model. The result is what gets applied or queued for approval.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Lead $lead, array $data): array
    {
        $stage = isset($data['stage']) ? LeadStage::from((string) $data['stage']) : $lead->stage;

        if ($stage !== LeadStage::Lost) {
            if ($lead->lost_reason !== null || array_key_exists('lost_reason', $data)) {
                $data['lost_reason'] = null;
            }
            if ($lead->lost_note !== null || array_key_exists('lost_note', $data)) {
                $data['lost_note'] = null;
            }
        }

        if (! $stage->isOpen() && ($lead->next_follow_up_on !== null || array_key_exists('next_follow_up_on', $data))) {
            $data['next_follow_up_on'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes  Output of prepare().
     * @param  list<int>|null  $serviceIds  New service set, or null to leave services unchanged.
     */
    public function handle(Lead $lead, array $changes, ?array $serviceIds = null): Lead
    {
        return DB::transaction(function () use ($lead, $changes, $serviceIds): Lead {
            $lead->fill($changes)->save();

            if ($serviceIds !== null) {
                $lead->services()->sync($serviceIds);

                if (! $lead->wasChanged()) {
                    $lead->touch();
                }
            }

            return $lead;
        });
    }
}
