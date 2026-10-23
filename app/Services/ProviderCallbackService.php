<?php

namespace App\Services;

use App\Models\IssuedPolicy;
use App\Models\MockInsurerRecord;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProviderCallbackService
{
    public function __construct(private readonly PolicyStateTransitionService $transitions)
    {
    }

    /**
     * @param array{provider_event_id: string, provider_reference: string, event_type: string, policy_number: string} $payload
     * @return array{duplicate: bool, policy_request: PolicyRequest}
     */
    public function process(array $payload): array
    {
        $mockRecord = MockInsurerRecord::query()
            ->where('provider_reference', $payload['provider_reference'])
            ->first();

        if ($mockRecord === null) {
            throw new NotFoundHttpException('No mock insurer request matches this provider reference.');
        }

        try {
            return DB::transaction(function () use ($payload, $mockRecord): array {
                $policyRequest = PolicyRequest::query()
                    ->whereKey($mockRecord->policy_request_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingEvent = ProviderEvent::query()
                    ->where('provider_event_id', $payload['provider_event_id'])
                    ->first();

                if ($existingEvent !== null) {
                    if ($existingEvent->payload != $payload) {
                        throw new ConflictHttpException(
                            'This provider_event_id was already used for different callback data.'
                        );
                    }

                    return ['duplicate' => true, 'policy_request' => $policyRequest];
                }

                $event = $policyRequest->providerEvents()->create([
                    'provider_event_id' => $payload['provider_event_id'],
                    'provider_reference' => $payload['provider_reference'],
                    'event_type' => $payload['event_type'],
                    'payload' => $payload,
                    'processing_status' => 'RECEIVED',
                ]);

                if ($policyRequest->state === 'ISSUED') {
                    $event->update([
                        'processing_status' => 'IGNORED_FINAL_STATE',
                        'processed_at' => now(),
                    ]);

                    return ['duplicate' => false, 'policy_request' => $policyRequest];
                }

                if (! in_array($policyRequest->state, ['SUBMITTED', 'PENDING_CONFIRMATION'], true)) {
                    $event->update([
                        'processing_status' => 'REJECTED_INVALID_STATE',
                        'processed_at' => now(),
                    ]);

                    return ['duplicate' => false, 'policy_request' => $policyRequest];
                }

                $policy = IssuedPolicy::query()->firstOrCreate(
                    ['policy_request_id' => $policyRequest->id],
                    [
                        'policy_number' => $payload['policy_number'],
                        'provider_reference' => $payload['provider_reference'],
                        'issued_at' => now(),
                    ]
                );

                $mockRecord->update([
                    'status' => 'ISSUED',
                    'policy_number' => $policy->policy_number,
                    'last_response' => [
                        'outcome' => 'ISSUED',
                        'policy_number' => $policy->policy_number,
                        'provider_reference' => $payload['provider_reference'],
                    ],
                ]);

                $this->transitions->transition(
                    $policyRequest,
                    'ISSUED',
                    'PROVIDER_CALLBACK',
                    'Provider confirmed policy '.$policy->policy_number.'.'
                );

                $policyRequest->issuanceAttempts()
                    ->whereNull('completed_at')
                    ->latest('attempt_number')
                    ->first()
                    ?->update([
                        'outcome' => 'ISSUED',
                        'provider_reference' => $payload['provider_reference'],
                        'response' => $payload,
                        'completed_at' => now(),
                    ]);

                $event->update([
                    'processing_status' => 'PROCESSED',
                    'processed_at' => now(),
                ]);

                return ['duplicate' => false, 'policy_request' => $policyRequest->fresh()];
            });
        } catch (QueryException $exception) {
            $existingEvent = ProviderEvent::query()
                ->where('provider_event_id', $payload['provider_event_id'])
                ->first();

            if ($existingEvent === null) {
                throw $exception;
            }

            if ($existingEvent->payload != $payload) {
                throw new ConflictHttpException(
                    'This provider_event_id was already used for different callback data.',
                    $exception
                );
            }

            return [
                'duplicate' => true,
                'policy_request' => $existingEvent->policyRequest,
            ];
        }
    }
}
