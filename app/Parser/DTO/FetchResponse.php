<?php

namespace App\Parser\DTO;

final readonly class FetchResponse
{
    public function __construct(
        public string $url,
        public int $statusCode,
        public string $body,
        public string $contentHash,
        public int $durationMs,
        public int $sizeBytes,
        public int $retryCount,
    ) {}
}
