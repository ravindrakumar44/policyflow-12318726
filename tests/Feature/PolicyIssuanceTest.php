<?php

namespace Tests\Feature;

use App\Models\IssuanceAttempt;
use App\Models\IssuedPolicy;
use App\Models\MockInsurerRecord;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\MockInsurerService;
use Tests\TestCase;

class PolicyIssuanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function createRequest(): array
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'ISSUE-'.uniqid(),
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ])->assertCreated();

        return $response->json('data');
    }

    public function test_successful_submission_issues_exactly_one_policy(): void
    {
        $request = $this->createRequest();

        $response = $this->postJson("/api/policy-requests/{$request['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED');

        $this->assertNotEmpty($response->json('data.policy_number'));
        $this->assertSame(1, IssuedPolicy::query()->where('policy_request_id', $request['id'])->count());
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $request['id'],
            'attempt_number' => 1,
            'outcome' => 'ISSUED',
        ]);
        $this->assertSame('225.00', PolicyRequest::findOrFail($request['id'])->premium);
    }

    public function test_temporary_failure_is_recorded_and_can_be_retried(): void
    {
        $request = $this->createRequest();

        $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'FAILED');

        $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'SUCCESS',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED');

        $this->assertSame(2, IssuanceAttempt::query()
            ->where('policy_request_id', $request['id'])
            ->count());
        $this->assertSame('225.00', PolicyRequest::findOrFail($request['id'])->premium);
    }

    public function test_timeout_after_issue_is_reconciled_without_a_second_issue(): void
    {
        $request = $this->createRequest();

        $response = $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED');

        $policyNumber = $response->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertSame(1, IssuedPolicy::query()->where('policy_request_id', $request['id'])->count());
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request['id'],
            'to_state' => 'PENDING_CONFIRMATION',
        ]);
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request['id'],
            'to_state' => 'ISSUED',
            'source' => 'RECONCILIATION',
        ]);

        $this->postJson("/api/policy-requests/{$request['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.policy_number', $policyNumber);

        $this->assertSame(1, IssuanceAttempt::query()
            ->where('policy_request_id', $request['id'])
            ->count());
    }

    public function test_repeated_submission_does_not_repeat_provider_call_or_policy_creation(): void
    {
        $request = $this->createRequest();
        $firstResponse = $this->postJson("/api/policy-requests/{$request['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED');

        $this->postJson("/api/policy-requests/{$request['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.policy_number', $firstResponse->json('data.policy_number'));

        $this->assertSame(1, IssuanceAttempt::query()
            ->where('policy_request_id', $request['id'])
            ->count());
        $this->assertSame(1, IssuedPolicy::query()
            ->where('policy_request_id', $request['id'])
            ->count());
    }

    public function test_stale_submitted_request_is_reconciled_before_any_retry(): void
    {
        $requestData = $this->createRequest();
        $policyRequest = PolicyRequest::findOrFail($requestData['id']);
        $record = MockInsurerRecord::query()->create([
            'policy_request_id' => $policyRequest->id,
            'scenario' => 'SUCCESS',
            'status' => 'NOT_SUBMITTED',
        ]);
        $attempt = $policyRequest->issuanceAttempts()->create([
            'attempt_number' => 1,
            'outcome' => 'PROCESSING',
            'started_at' => now(),
        ]);

        $providerResponse = app(MockInsurerService::class)->submit($policyRequest);
        $policyRequest->update(['state' => 'SUBMITTED']);

        $this->postJson("/api/policy-requests/{$policyRequest->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.policy_number', $providerResponse['policy_number']);

        $this->assertSame(1, IssuanceAttempt::query()
            ->where('policy_request_id', $policyRequest->id)
            ->count());
        $this->assertSame(1, IssuedPolicy::query()
            ->where('policy_request_id', $policyRequest->id)
            ->count());
        $this->assertSame('ISSUED', $record->fresh()->status);
        $this->assertSame('ISSUED', $attempt->fresh()->outcome);
    }

    public function test_delayed_callback_job_transitions_pending_request_to_issued(): void
    {
        $request = $this->createRequest();

        $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'PENDING_CONFIRMATION');

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('PENDING_CONFIRMATION', PolicyRequest::findOrFail($request['id'])->state);

        $this->travel(2)->seconds();
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'default',
            '--tries' => 1,
        ])->assertExitCode(0);

        $this->assertSame('ISSUED', PolicyRequest::findOrFail($request['id'])->state);
        $this->assertSame(1, IssuedPolicy::query()->where('policy_request_id', $request['id'])->count());
        $this->assertDatabaseHas('provider_events', [
            'policy_request_id' => $request['id'],
            'event_type' => 'POLICY_ISSUED',
            'processing_status' => 'PROCESSED',
        ]);
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request['id'],
            'from_state' => 'PENDING_CONFIRMATION',
            'to_state' => 'ISSUED',
            'source' => 'PROVIDER_CALLBACK',
        ]);
    }

    public function test_duplicate_callbacks_are_recorded_and_applied_only_once(): void
    {
        $request = $this->createRequest();

        $pendingResponse = $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'PENDING_CONFIRMATION');

        $callback = [
            'provider_event_id' => 'CALLBACK-'.uniqid(),
            'provider_reference' => $pendingResponse->json('data.provider_reference'),
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-'.strtoupper(bin2hex(random_bytes(6))),
        ];

        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.state', 'ISSUED');

        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.state', 'ISSUED');

        $this->assertSame(1, ProviderEvent::query()
            ->where('provider_event_id', $callback['provider_event_id'])
            ->count());
        $this->assertSame(1, IssuedPolicy::query()
            ->where('policy_request_id', $request['id'])
            ->count());
        $this->assertSame(1, PolicyRequest::findOrFail($request['id'])
            ->stateHistory()
            ->where('to_state', 'ISSUED')
            ->count());
    }

    public function test_duplicate_callback_mock_scenario_delivers_same_event_multiple_times(): void
    {
        $request = $this->createRequest();

        $this->postJson("/api/policy-requests/{$request['id']}/submit", [
            'scenario' => 'DUPLICATE_CALLBACK',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUED');

        $this->assertSame(1, ProviderEvent::query()
            ->where('policy_request_id', $request['id'])
            ->count());
        $this->assertSame(1, IssuedPolicy::query()
            ->where('policy_request_id', $request['id'])
            ->count());
    }
}
