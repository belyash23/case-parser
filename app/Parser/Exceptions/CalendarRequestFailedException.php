<?php

namespace App\Parser\Exceptions;

use RuntimeException;

class CalendarRequestFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
    ) {
        parent::__construct("Calendar request returned HTTP {$statusCode}: {$url}");
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'url' => $this->url,
            'status_code' => $this->statusCode,
        ];
    }
}
