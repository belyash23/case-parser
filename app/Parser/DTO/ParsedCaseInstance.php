<?php

namespace App\Parser\DTO;

use Carbon\CarbonImmutable;

final readonly class ParsedCaseInstance
{
    /**
     * @param  array<int, ParsedCaseEvent>  $events
     * @param  array<int, ParsedDocument>  $documents
     * @param  array<int, ParsedCaseParty>  $parties
     */
    public function __construct(
        public string $sourceUrl,
        public ?string $caseNumber,
        public ?string $normalizedCaseNumber,
        public ?string $caseUid,
        public ?string $externalCaseId,
        public ?string $proceedingType,
        public string $instanceLevel,
        public ?string $statusRaw,
        public ?string $statusNormalized,
        public ?string $resultRaw,
        public ?string $resultNormalized,
        public ?CarbonImmutable $receivedDate,
        public ?CarbonImmutable $completedAt,
        public ?string $categoryRaw,
        public ?string $categoryNormalized,
        public array $events = [],
        public array $documents = [],
        public array $parties = [],
    ) {}
}
