<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MockInsurerRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_request_id',
        'scenario',
        'status',
        'provider_reference',
        'policy_number',
        'last_response',
    ];

    protected function casts(): array
    {
        return [
            'last_response' => 'array',
        ];
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
