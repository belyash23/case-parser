<?php

namespace App\Parser\DTO;

final readonly class ParsedCaseParty
{
    public function __construct(
        public string $role,
        public string $roleGroup,
        public string $partyType,
        public bool $isHidden = false,
        public ?string $sourceRole = null,
        public int $confidence = 0,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->role,
            $this->roleGroup,
            $this->partyType,
            $this->isHidden ? '1' : '0',
            $this->sourceRole ?? '',
            $this->confidence,
        ]));
    }
}