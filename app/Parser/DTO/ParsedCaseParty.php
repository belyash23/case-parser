<?php

namespace App\Parser\DTO;

final readonly class ParsedCaseParty
{
    public function __construct(
        public string $role,
        public string $partyType,
        public ?string $sourceRole = null,
        public int $confidence = 0,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->role,
            $this->partyType,
            $this->sourceRole ?? '',
            $this->confidence,
        ]));
    }
}
