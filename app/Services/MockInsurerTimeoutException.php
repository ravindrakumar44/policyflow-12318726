<?php

namespace App\Services;

use RuntimeException;

class MockInsurerTimeoutException extends RuntimeException
{
    /**
     * @param array<string, mixed> $providerResponse
     */
    public function __construct(
        public readonly array $providerResponse,
        string $message = 'The mock insurer timed out after processing the policy.'
    ) {
        parent::__construct($message);
    }
}
