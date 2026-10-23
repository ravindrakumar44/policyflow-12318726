<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Requests\ProviderCallbackRequest;
use App\Http\Requests\SubmitPolicyRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest as PolicyRequestModel;
use App\Services\PolicyIssuanceService;
use App\Services\ProviderCallbackService;
use App\Services\PolicyRequestIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    private const STATES = [
        'RECEIVED',
        'VALIDATED',
        'SUBMITTED',
        'PENDING_CONFIRMATION',
        'ISSUED',
        'FAILED',
        'NEEDS_RECONCILIATION',
    ];

    public function store(
        StorePolicyRequest $request,
        PolicyRequestIntakeService $intakeService
    ): JsonResponse {
        $result = $intakeService->create($request->validated());
        $policyRequest = $result['request']->load('stateHistory');

        return (new PolicyRequestResource($policyRequest))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(PolicyRequestModel $policyRequest): PolicyRequestResource
    {
        return new PolicyRequestResource($policyRequest->load([
            'stateHistory',
            'mockInsurerRecord',
            'issuedPolicy',
        ]));
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::STATES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = PolicyRequestModel::query()
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('state', $validated['status'])
            )
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PolicyRequestResource::collection($requests);
    }

    public function submit(
        SubmitPolicyRequest $request,
        PolicyRequestModel $policyRequest,
        PolicyIssuanceService $issuanceService
    ): PolicyRequestResource {
        $updatedRequest = $issuanceService->submit(
            $policyRequest,
            $request->validated('scenario')
        );

        return new PolicyRequestResource($updatedRequest);
    }

    public function callback(
        ProviderCallbackRequest $request,
        ProviderCallbackService $callbackService
    ): JsonResponse {
        $result = $callbackService->process($request->validated());

        return response()->json([
            'message' => $result['duplicate'] ? 'Callback already processed.' : 'Callback processed.',
            'duplicate' => $result['duplicate'],
            'data' => (new PolicyRequestResource($result['policy_request']->load([
                'stateHistory',
                'mockInsurerRecord',
                'issuedPolicy',
            ])))->resolve(),
        ]);
    }
}
