<?php

namespace App\Actions\Leads;

use App\Enums\LeadStage;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateLead
{
    /**
     * Creates a lead, and the client too when `$newClient` is given (data-model 4.8). The owner defaults
     * to the acting user; a new client is owned by the lead owner.
     *
     * @param  array<string, mixed>  $data  Validated lead attributes.
     * @param  list<int>  $serviceIds
     * @param  array<string, mixed>|null  $newClient  Validated client attributes (discord_username, name, email).
     */
    public function handle(User $actor, array $data, array $serviceIds = [], ?array $newClient = null): Lead
    {
        return DB::transaction(function () use ($actor, $data, $serviceIds, $newClient): Lead {
            $data['owner_id'] ??= $actor->id;
            $data['stage'] ??= LeadStage::New->value;
            $data['contacted_on'] ??= today()->toDateString();
            $data['currency'] ??= 'USD';

            if ($newClient !== null) {
                $data['client_id'] = Client::query()->create([...$newClient, 'owner_id' => $data['owner_id']])->id;
            }

            $stage = LeadStage::from((string) $data['stage']);
            if ($stage !== LeadStage::Lost) {
                $data['lost_reason'] = null;
                $data['lost_note'] = null;
            }
            if (! $stage->isOpen()) {
                $data['next_follow_up_on'] = null;
            }

            $lead = Lead::query()->create($data);
            $lead->services()->sync($serviceIds);

            return $lead;
        });
    }
}
