<?php

namespace App\Services;

use App\Jobs\DeliverDelayedMockInsurerCallback;
use App\Models\MockInsurerRecord;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * @return array<string, mixed>
     */
    public function submit(PolicyRequest $policyRequest): array
    {
        $record = DB::transaction(function () use ($policyRequest): MockInsurerRecord {
            $record = MockInsurerRecord::query()
                ->where('policy_request_id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->policy_number !== null) {
                return $record;
            }

            $providerReference = $record->provider_reference ?? 'MOCK-'.Str::upper(Str::random(12));

            if ($record->scenario === 'TEMPORARY_FAILURE') {
                $record->status = 'FAILED';
                $record->provider_reference = $providerReference;
                $record->last_response = [
                    'outcome' => 'TEMPORARY_FAILURE',
                    'message' => 'Temporary mock insurer failure.',
                    'provider_reference' => $providerReference,
                ];
                $record->save();

                return $record;
            }

            if ($record->scenario === 'DELAYED_CALLBACK') {
                $record->status = 'PENDING_CONFIRMATION';
                $record->provider_reference = $providerReference;
                $record->last_response = [
                    'outcome' => 'PENDING_CONFIRMATION',
                    'message' => 'The mock insurer will confirm this policy by callback.',
                    'provider_reference' => $providerReference,
                    'scenario' => 'DELAYED_CALLBACK',
                ];
                $record->save();

                return $record;
            }

            $policyNumber = 'POL-'.Str::upper(Str::random(12));
            $record->status = 'ISSUED';
            $record->provider_reference = $providerReference;
            $record->policy_number = $policyNumber;
            $record->last_response = [
                'outcome' => 'ISSUED',
                'policy_number' => $policyNumber,
                'provider_reference' => $providerReference,
            ];
            $record->save();

            return $record;
        });

        $response = $record->last_response ?? [];

        if ($record->scenario === 'TIMEOUT_AFTER_ISSUE') {
            throw new MockInsurerTimeoutException($response);
        }

        if ($record->scenario === 'DUPLICATE_CALLBACK' && $record->policy_number !== null) {
            $response['callback_event'] = [
                'provider_event_id' => 'EVENT-'.$record->provider_reference,
                'provider_reference' => $record->provider_reference,
                'event_type' => 'POLICY_ISSUED',
                'policy_number' => $record->policy_number,
            ];
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(PolicyRequest $policyRequest): ?array
    {
        $record = MockInsurerRecord::query()
            ->where('policy_request_id', $policyRequest->id)
            ->first();

        if ($record === null || $record->policy_number === null) {
            return null;
        }

        return [
            'outcome' => 'ISSUED',
            'policy_number' => $record->policy_number,
            'provider_reference' => $record->provider_reference,
        ];
    }

    public function scheduleDelayedCallback(PolicyRequest $policyRequest): void
    {
        $record = $policyRequest->mockInsurerRecord;

        if ($record === null || $record->scenario !== 'DELAYED_CALLBACK') {
            return;
        }

        DeliverDelayedMockInsurerCallback::dispatch($record->id)
            ->onConnection('database')
            ->delay(now()->addSecond());
    }
}
