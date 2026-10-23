<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    public function test_valid_request_calculates_and_persists_the_expected_premium_snapshot(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.state', 'RECEIVED')
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.pricing_breakdown.travel_days', 5)
            ->assertJsonPath('data.pricing_breakdown.age_at_trip_start', 28)
            ->assertJsonPath('data.pricing_breakdown.age_multiplier', '1.00')
            ->assertJsonPath('data.pricing_breakdown.destination_multiplier', '1.80')
            ->assertJsonPath('data.pricing_breakdown.coverage_multiplier', '1.25');

        $this->assertDatabaseHas('policy_requests', [
            'agent_id' => 'AGENT-17',
            'client_request_id' => 'AGT-2026-0001',
            'premium' => '225.00',
        ]);
        $this->assertDatabaseHas('policy_state_history', [
            'to_state' => 'RECEIVED',
            'source' => 'INTAKE',
        ]);
    }

    public function test_invalid_trip_dates_are_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-14',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '10-11-2026',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trip_start']);
    }

    public function test_unsupported_plan_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'ULTIMATE',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_unsupported_coverage_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload([
            'sum_insured' => 200000,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_identical_duplicate_returns_the_original_request_and_premium(): void
    {
        $payload = $this->validPayload();
        $firstResponse = $this->postJson('/api/policy-requests', $payload)->assertCreated();
        $firstId = $firstResponse->json('data.id');

        $this->postJson('/api/policy-requests', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.premium', '225.00');

        $this->assertSame(1, PolicyRequest::query()->count());
    }

    public function test_database_unique_constraint_rejects_a_duplicate_intake_insert(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();
        $existing = PolicyRequest::query()->firstOrFail();
        $duplicateAttributes = $existing->getAttributes();
        unset($duplicateAttributes['id'], $duplicateAttributes['created_at'], $duplicateAttributes['updated_at']);
        $duplicateAttributes['uuid'] = (string) Str::uuid();

        try {
            PolicyRequest::query()->create($duplicateAttributes);
            $this->fail('The database unique constraint should reject a duplicate intake key.');
        } catch (QueryException) {
            $this->assertSame(1, PolicyRequest::query()->count());
        }
    }

    public function test_duplicate_key_with_different_data_returns_conflict(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();

        $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'BASIC',
        ]))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This agent_id and client_request_id are already associated with different request data.'
            );

        $this->assertSame(1, PolicyRequest::query()->count());
    }

    public function test_request_can_be_retrieved_with_its_premium_and_audit_trail(): void
    {
        $created = $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();
        $id = $created->json('data.id');

        $this->getJson("/api/policy-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.audit_trail.0.to_state', 'RECEIVED');
    }

    public function test_requests_can_be_filtered_by_status_and_paginated(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();
        $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'AGT-2026-0002',
        ]))->assertCreated();

        $this->getJson('/api/policy-requests?status=RECEIVED&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.state', 'RECEIVED');
    }

    public function test_all_required_request_fields_are_validated(): void
    {
        $this->postJson('/api/policy-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }
}
