<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'pricing_breakdown',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'trip_start' => 'date',
            'trip_end' => 'date',
            'sum_insured' => 'integer',
            'premium' => 'decimal:2',
            'pricing_breakdown' => 'array',
        ];
    }

    public function mockInsurerRecord(): HasOne
    {
        return $this->hasOne(MockInsurerRecord::class);
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function issuedPolicy(): HasOne
    {
        return $this->hasOne(IssuedPolicy::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class);
    }
}
