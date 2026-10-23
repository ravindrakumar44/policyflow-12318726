<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth->toDateString(),
            'destination' => $this->destination,
            'trip_start' => $this->trip_start->toDateString(),
            'trip_end' => $this->trip_end->toDateString(),
            'plan_code' => $this->plan_code,
            'sum_insured' => $this->sum_insured,
            'premium' => $this->premium,
            'pricing_breakdown' => $this->pricing_breakdown,
            'state' => $this->state,
            'provider_reference' => $this->whenLoaded(
                'mockInsurerRecord',
                fn () => $this->mockInsurerRecord?->provider_reference
            ),
            'policy_number' => $this->whenLoaded(
                'issuedPolicy',
                fn () => $this->issuedPolicy?->policy_number
            ),
            'audit_trail' => $this->whenLoaded(
                'stateHistory',
                fn () => $this->stateHistory->map(fn ($entry) => [
                    'from_state' => $entry->from_state,
                    'to_state' => $entry->to_state,
                    'source' => $entry->source,
                    'reason' => $entry->reason,
                    'created_at' => $entry->created_at?->toISOString(),
                ])->values()
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
