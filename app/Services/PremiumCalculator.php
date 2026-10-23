<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PremiumCalculator
{
    private const BASE_DAILY_RATES = [
        'BASIC' => 12,
        'PLUS' => 20,
        'PREMIUM' => 30,
    ];

    private const AGE_MULTIPLIERS = [
        '0-17' => 110,
        '18-60' => 100,
        '61-70' => 150,
        '71+' => 200,
    ];

    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => 100,
        'INTERNATIONAL' => 180,
    ];

    private const COVERAGE_MULTIPLIERS = [
        100000 => 100,
        300000 => 125,
        500000 => 150,
    ];

    /**
     * @param array<string, mixed> $attributes
     * @return array{premium: string, breakdown: array<string, int|string>}
     */
    public function calculate(array $attributes): array
    {
        $planCode = $attributes['plan_code'];
        $destination = $attributes['destination'];
        $sumInsured = (int) $attributes['sum_insured'];

        if (! isset(self::BASE_DAILY_RATES[$planCode])
            || ! isset(self::DESTINATION_MULTIPLIERS[$destination])
            || ! isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException('Unsupported policy pricing input.');
        }

        $tripStart = CarbonImmutable::createFromFormat('!Y-m-d', $attributes['trip_start']);
        $tripEnd = CarbonImmutable::createFromFormat('!Y-m-d', $attributes['trip_end']);
        $dateOfBirth = CarbonImmutable::createFromFormat('!Y-m-d', $attributes['date_of_birth']);

        if ($tripStart === false || $tripEnd === false || $dateOfBirth === false) {
            throw new InvalidArgumentException('Policy dates must use the Y-m-d format.');
        }

        $age = $dateOfBirth->diff($tripStart)->y;
        $ageBand = match (true) {
            $age <= 17 => '0-17',
            $age <= 60 => '18-60',
            $age <= 70 => '61-70',
            default => '71+',
        };
        $travelDays = (int) $tripStart->diffInDays($tripEnd) + 1;
        $baseDailyRate = self::BASE_DAILY_RATES[$planCode];
        $ageMultiplier = self::AGE_MULTIPLIERS[$ageBand];
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$destination];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        $premiumNumerator = $baseDailyRate
            * $travelDays
            * $ageMultiplier
            * $destinationMultiplier
            * $coverageMultiplier;

        $premiumInPaise = intdiv($premiumNumerator + 5000, 10000);
        $premium = sprintf('%d.%02d', intdiv($premiumInPaise, 100), $premiumInPaise % 100);

        return [
            'premium' => $premium,
            'breakdown' => [
                'base_daily_rate' => $baseDailyRate,
                'travel_days' => $travelDays,
                'age_at_trip_start' => $age,
                'age_multiplier' => $this->formatMultiplier($ageMultiplier),
                'destination_multiplier' => $this->formatMultiplier($destinationMultiplier),
                'coverage_multiplier' => $this->formatMultiplier($coverageMultiplier),
                'premium' => $premium,
            ],
        ];
    }

    private function formatMultiplier(int $multiplier): string
    {
        return sprintf('%d.%02d', intdiv($multiplier, 100), $multiplier % 100);
    }
}
