<?php

namespace App\Parser\DTO;

use Carbon\CarbonImmutable;

final readonly class ParsedDocument
{
    public function __construct(
        public ?string $documentTypeRaw,
        public ?string $documentTypeNormalized,
        public ?string $documentNumber,
        public ?CarbonImmutable $documentDate,
        public ?string $documentKind,
        public ?string $sourceUrl,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->documentTypeRaw ?? '',
            $this->documentNumber ?? '',
            $this->documentDate?->toDateString() ?? '',
            $this->sourceUrl ?? '',
        ]));
    }
}
