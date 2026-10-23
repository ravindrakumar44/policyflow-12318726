<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;
use LogicException;

class PolicyStateTransitionService
{
    private const ALLOWED_TRANSITIONS = [
        'RECEIVED' => ['VALIDATED'],
        'VALIDATED' => ['SUBMITTED'],
        'SUBMITTED' => ['PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'FAILED' => ['VALIDATED'],
        'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED', 'PENDING_CONFIRMATION'],
        'ISSUED' => [],
    ];

    public function transition(
        PolicyRequest $policyRequest,
        string $toState,
        string $source,
        ?string $reason = null
    ): void {
        $fromState = $policyRequest->state;

        if (! in_array($toState, self::ALLOWED_TRANSITIONS[$fromState] ?? [], true)) {
            throw new LogicException("Invalid policy state transition: {$fromState} -> {$toState}.");
        }

        $policyRequest->state = $toState;
        $policyRequest->save();

        $policyRequest->stateHistory()->create([
            'from_state' => $fromState,
            'to_state' => $toState,
            'source' => $source,
            'reason' => $reason,
        ]);
    }
}
