<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PolicyRequestIntakeService
{
    private const REQUEST_FIELDS = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
    ];

    public function __construct(private readonly PremiumCalculator $premiumCalculator)
    {
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{request: PolicyRequest, created: bool}
     */
    public function create(array $attributes): array
    {
        $attributes = $this->normalize($attributes);
        $existing = PolicyRequest::query()
            ->where('agent_id', $attributes['agent_id'])
            ->where('client_request_id', $attributes['client_request_id'])
            ->first();

        if ($existing !== null) {
            return $this->existingResult($existing, $attributes);
        }

        $pricing = $this->premiumCalculator->calculate($attributes);

        try {
            $request = DB::transaction(function () use ($attributes, $pricing): PolicyRequest {
                $request = PolicyRequest::query()->create([
                    ...$attributes,
                    'uuid' => (string) Str::uuid(),
                    'premium' => $pricing['premium'],
                    'pricing_breakdown' => $pricing['breakdown'],
                    'state' => 'RECEIVED',
                ]);

                $request->stateHistory()->create([
                    'from_state' => null,
                    'to_state' => 'RECEIVED',
                    'source' => 'INTAKE',
                    'reason' => 'Policy request accepted.',
                ]);

                return $request;
            });
        } catch (QueryException $exception) {
            $existing = PolicyRequest::query()
                ->where('agent_id', $attributes['agent_id'])
                ->where('client_request_id', $attributes['client_request_id'])
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->existingResult($existing, $attributes);
        }

        return ['request' => $request, 'created' => true];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{request: PolicyRequest, created: false}
     */
    private function existingResult(PolicyRequest $request, array $attributes): array
    {
        foreach (self::REQUEST_FIELDS as $field) {
            $storedValue = $request->getRawOriginal($field);

            if (in_array($field, ['date_of_birth', 'trip_start', 'trip_end'], true)) {
                $storedValue = substr((string) $storedValue, 0, 10);
            }

            if ((string) $storedValue !== (string) $attributes[$field]) {
                throw new ConflictHttpException(
                    'This agent_id and client_request_id are already associated with different request data.'
                );
            }
        }

        return ['request' => $request, 'created' => false];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $attributes = array_intersect_key($attributes, array_flip(self::REQUEST_FIELDS));
        $attributes['sum_insured'] = (int) $attributes['sum_insured'];

        foreach (['date_of_birth', 'trip_start', 'trip_end'] as $field) {
            $attributes[$field] = (string) $attributes[$field];
        }

        return $attributes;
    }
}
