<?php

namespace App\Parser\Exceptions;

use DateTimeInterface;
use RuntimeException;

class SourceCircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?DateTimeInterface $cooldownUntil,
        public readonly ?string $reason,
    ) {
        parent::__construct(sprintf(
            'Requests to %s are paused%s%s.',
            $sourceType,
            $cooldownUntil instanceof DateTimeInterface ? ' until '.$cooldownUntil->format(DateTimeInterface::ATOM) : '',
            $reason !== null ? ' ('.$reason.')' : '',
        ));
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'source_type' => $this->sourceType,
            'cooldown_until' => $this->cooldownUntil?->format(DateTimeInterface::ATOM),
            'reason' => $this->reason,
        ];
    }
}
