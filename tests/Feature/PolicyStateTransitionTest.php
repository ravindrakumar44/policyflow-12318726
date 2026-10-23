<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Services\PolicyStateTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PolicyStateTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function createRequest(): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'STATE-001',
            'agent_id' => 'AGENT-STATE',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-10',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ])->assertCreated();

        return PolicyRequest::findOrFail($response->json('data.id'));
    }

    public function test_valid_initial_state_transitions_are_recorded(): void
    {
        $policyRequest = $this->createRequest();
        $transitions = app(PolicyStateTransitionService::class);

        $transitions->transition($policyRequest, 'VALIDATED', 'TEST');
        $transitions->transition($policyRequest, 'SUBMITTED', 'TEST');

        $policyRequest->refresh();

        $this->assertSame('SUBMITTED', $policyRequest->state);
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $policyRequest->id,
            'from_state' => 'RECEIVED',
            'to_state' => 'VALIDATED',
            'source' => 'TEST',
        ]);
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $policyRequest->id,
            'from_state' => 'VALIDATED',
            'to_state' => 'SUBMITTED',
            'source' => 'TEST',
        ]);
    }

    public function test_invalid_transition_is_rejected_without_changing_state(): void
    {
        $policyRequest = $this->createRequest();

        try {
            app(PolicyStateTransitionService::class)->transition($policyRequest, 'ISSUED', 'TEST');
            $this->fail('An invalid state transition should throw LogicException.');
        } catch (LogicException $exception) {
            $this->assertSame('Invalid policy state transition: RECEIVED -> ISSUED.', $exception->getMessage());
        }

        $this->assertSame('RECEIVED', $policyRequest->fresh()->state);
        $this->assertSame(1, $policyRequest->stateHistory()->count());
    }

    public function test_issued_state_cannot_transition_to_any_other_state(): void
    {
        $policyRequest = $this->createRequest();
        $transitions = app(PolicyStateTransitionService::class);
        $transitions->transition($policyRequest, 'VALIDATED', 'TEST');
        $transitions->transition($policyRequest, 'SUBMITTED', 'TEST');
        $transitions->transition($policyRequest, 'ISSUED', 'TEST');

        foreach ([
            'RECEIVED',
            'VALIDATED',
            'SUBMITTED',
            'PENDING_CONFIRMATION',
            'FAILED',
            'NEEDS_RECONCILIATION',
        ] as $toState) {
            try {
                $transitions->transition($policyRequest, $toState, 'TEST');
                $this->fail("ISSUED -> {$toState} should be rejected.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    "Invalid policy state transition: ISSUED -> {$toState}.",
                    $exception->getMessage()
                );
            }
        }

        $this->assertSame('ISSUED', $policyRequest->fresh()->state);
        $this->assertSame(4, $policyRequest->stateHistory()->count());
    }
}
