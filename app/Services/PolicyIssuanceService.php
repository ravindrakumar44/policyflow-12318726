<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\MockInsurerRecord;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PolicyIssuanceService
{
    public function __construct(
        private readonly MockInsurerService $mockInsurer,
        private readonly ProviderCallbackService $callbacks,
        private readonly PolicyStateTransitionService $transitions
    ) {
    }

    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): PolicyRequest
    {
        $preparation = DB::transaction(function () use ($policyRequest, $scenario): array {
            $lockedRequest = PolicyRequest::query()->lockForUpdate()->findOrFail($policyRequest->id);

            if ($lockedRequest->state === 'ISSUED') {
                return ['action' => 'return', 'request' => $lockedRequest];
            }

            if (in_array($lockedRequest->state, ['SUBMITTED', 'PENDING_CONFIRMATION'], true)) {
                return ['action' => 'reconcile', 'request' => $lockedRequest];
            }

            if (! in_array($lockedRequest->state, ['RECEIVED', 'FAILED', 'NEEDS_RECONCILIATION'], true)) {
                throw new LogicException("Cannot submit a policy request in state {$lockedRequest->state}.");
            }

            $record = MockInsurerRecord::query()->firstOrCreate(
                ['policy_request_id' => $lockedRequest->id],
                ['scenario' => $scenario ?? 'SUCCESS', 'status' => 'NOT_SUBMITTED']
            );

            if ($scenario !== null && in_array($record->status, ['NOT_SUBMITTED', 'FAILED'], true)) {
                $record->scenario = $scenario;
                $record->status = 'NOT_SUBMITTED';
                $record->save();
            }

            if ($lockedRequest->state === 'RECEIVED' || $lockedRequest->state === 'FAILED') {
                $this->transitions->transition(
                    $lockedRequest,
                    'VALIDATED',
                    'ISSUANCE',
                    'Request passed intake validation.'
                );
            } elseif ($lockedRequest->state === 'NEEDS_RECONCILIATION') {
                return ['action' => 'reconcile', 'request' => $lockedRequest];
            }

            $this->transitions->transition(
                $lockedRequest,
                'SUBMITTED',
                'ISSUANCE',
                'Request submitted to the mock insurer.'
            );

            $attemptNumber = ((int) $lockedRequest->issuanceAttempts()->max('attempt_number')) + 1;
            $attempt = $lockedRequest->issuanceAttempts()->create([
                'attempt_number' => $attemptNumber,
                'outcome' => 'PROCESSING',
                'started_at' => now(),
            ]);

            return ['action' => 'submit', 'request' => $lockedRequest, 'attempt' => $attempt];
        });

        if ($preparation['action'] === 'return') {
            return $preparation['request']->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        }

        if ($preparation['action'] === 'reconcile') {
            return $this->reconcile($preparation['request']);
        }

        /** @var PolicyRequest $request */
        $request = $preparation['request'];
        /** @var IssuanceAttempt $attempt */
        $attempt = $preparation['attempt'];

        try {
            $response = $this->mockInsurer->submit($request);
        } catch (MockInsurerTimeoutException $exception) {
            $this->markPending($request, $attempt, $exception->getMessage(), $exception->providerResponse);

            return $this->reconcile($request);
        }

        if (($response['outcome'] ?? null) === 'TEMPORARY_FAILURE') {
            return $this->markFailed($request, $attempt, $response);
        }

        if (($response['outcome'] ?? null) === 'PENDING_CONFIRMATION') {
            $pendingRequest = $this->markPending(
                $request,
                $attempt,
                $response['message'] ?? 'Awaiting provider callback.',
                $response
            );

            if (($response['scenario'] ?? null) === 'DELAYED_CALLBACK') {
                $this->mockInsurer->scheduleDelayedCallback($request);
            }

            return $pendingRequest;
        }

        if (isset($response['callback_event'])) {
            $callback = $response['callback_event'];
            $this->callbacks->process($callback);
            $this->callbacks->process($callback);
            $this->callbacks->process($callback);

            return $request->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        }

        return $this->markIssued($request, $attempt, $response);
    }

    private function reconcile(PolicyRequest $policyRequest): PolicyRequest
    {
        $providerResponse = $this->mockInsurer->lookup($policyRequest);

        if ($providerResponse === null) {
            $record = $policyRequest->mockInsurerRecord;

            if ($record?->scenario === 'DELAYED_CALLBACK' && $record->status === 'PENDING_CONFIRMATION') {
                $this->mockInsurer->scheduleDelayedCallback($policyRequest);
            }

            return $policyRequest->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        }

        $attempt = $policyRequest->issuanceAttempts()
            ->latest('attempt_number')
            ->firstOrFail();

        return $this->markIssued($policyRequest, $attempt, [
            ...$providerResponse,
            'reconciled' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function markIssued(
        PolicyRequest $policyRequest,
        IssuanceAttempt $attempt,
        array $response
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $attempt, $response): PolicyRequest {
            $lockedRequest = PolicyRequest::query()->lockForUpdate()->findOrFail($policyRequest->id);

            if ($lockedRequest->state !== 'ISSUED') {
                $lockedRequest->issuedPolicy()->firstOrCreate(
                    [],
                    [
                        'policy_number' => $response['policy_number'],
                        'provider_reference' => $response['provider_reference'] ?? null,
                        'issued_at' => now(),
                    ]
                );

                $this->transitions->transition(
                    $lockedRequest,
                    'ISSUED',
                    isset($response['reconciled']) ? 'RECONCILIATION' : 'MOCK_INSURER',
                    'Provider confirmed policy '.$response['policy_number'].'.'
                );
            }

            $attempt->update([
                'outcome' => 'ISSUED',
                'provider_reference' => $response['provider_reference'] ?? null,
                'response' => $response,
                'completed_at' => now(),
            ]);

            return $lockedRequest->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        });
    }

    /**
     * @param array<string, mixed> $response
     */
    private function markFailed(
        PolicyRequest $policyRequest,
        IssuanceAttempt $attempt,
        array $response
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $attempt, $response): PolicyRequest {
            $lockedRequest = PolicyRequest::query()->lockForUpdate()->findOrFail($policyRequest->id);

            if ($lockedRequest->state !== 'ISSUED') {
                $this->transitions->transition(
                    $lockedRequest,
                    'FAILED',
                    'MOCK_INSURER',
                    $response['message'] ?? 'Temporary provider failure.'
                );
            }

            $attempt->update([
                'outcome' => 'FAILED',
                'provider_reference' => $response['provider_reference'] ?? null,
                'response' => $response,
                'error_message' => $response['message'] ?? null,
                'completed_at' => now(),
            ]);

            return $lockedRequest->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        });
    }

    /**
     * @param array<string, mixed> $response
     */
    private function markPending(
        PolicyRequest $policyRequest,
        IssuanceAttempt $attempt,
        string $reason,
        array $response
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $attempt, $reason, $response): PolicyRequest {
            $lockedRequest = PolicyRequest::query()->lockForUpdate()->findOrFail($policyRequest->id);

            if ($lockedRequest->state === 'SUBMITTED') {
                $this->transitions->transition(
                    $lockedRequest,
                    'PENDING_CONFIRMATION',
                    'MOCK_INSURER',
                    $reason
                );
            }

            $attempt->update([
                'outcome' => 'PENDING_CONFIRMATION',
                'provider_reference' => $response['provider_reference'] ?? null,
                'response' => $response,
                'error_message' => $reason,
                'completed_at' => now(),
            ]);

            return $lockedRequest->fresh(['mockInsurerRecord', 'issuedPolicy', 'stateHistory']);
        });
    }
}
