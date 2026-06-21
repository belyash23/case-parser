<?php

namespace App\Parser\DTO;

use Carbon\CarbonImmutable;

final readonly class ParsedCaseEvent
{
    public function __construct(
        public int $order,
        public ?CarbonImmutable $eventDate,
        public ?string $eventTime,
        public string $eventTypeRaw,
        public string $eventTypeNormalized,
        public ?string $eventResultRaw,
        public ?string $eventResultNormalized,
        public ?string $sourceUrl = null,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->order,
            $this->eventDate?->toDateString() ?? '',
            $this->eventTime ?? '',
            $this->eventTypeRaw,
            $this->eventResultRaw ?? '',
        ]));
    }
}
