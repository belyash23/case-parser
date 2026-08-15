<?php

namespace App\Parser\DTO;

final readonly class AvailabilityProbeResult
{
    public function __construct(
        public string $url,
        public string $outcome,
        public ?int $httpStatus,
        public ?int $durationMs,
        public ?int $responseSizeBytes,
        public ?int $retryAfterSeconds,
        public ?string $errorType,
        public ?string $errorMessage,
        public ?string $responseHash,
    ) {}
}
