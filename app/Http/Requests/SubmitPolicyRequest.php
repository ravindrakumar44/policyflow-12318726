<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scenario' => [
                'sometimes',
                'string',
                Rule::in([
                    'SUCCESS',
                    'TEMPORARY_FAILURE',
                    'TIMEOUT_AFTER_ISSUE',
                    'DELAYED_CALLBACK',
                    'DUPLICATE_CALLBACK',
                ]),
                Rule::prohibitedIf(! app()->environment('local', 'testing')),
            ],
        ];
    }
}
