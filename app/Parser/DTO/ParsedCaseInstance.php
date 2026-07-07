<?php

namespace App\Parser\DTO;

use Carbon\CarbonImmutable;

final readonly class ParsedCaseInstance
{
    /**
     * @param  array<int, string>  $normalizedCaseNumberAliases
     * @param  array<int, string>  $categoryPath
     * @param  array<int, ParsedCaseEvent>  $events
     * @param  array<int, ParsedDocument>  $documents
     * @param  array<int, ParsedCaseParty>  $parties
     */
    public function __construct(
        public string $sourceUrl,
        public ?string $caseNumber,
        public ?string $normalizedCaseNumber,
        public array $normalizedCaseNumberAliases,
        public ?string $caseUid,
        public ?string $externalCaseId,
        public ?string $sourceCaseTypeId,
        public ?string $caseType,
        public string $instanceLevel,
        public ?string $courtInstanceStatusRaw,
        public ?string $courtInstanceStatusNormalized,
        public ?string $disputeStatusNormalized,
        public ?string $dispositionType,
        public ?string $resultRaw,
        public ?string $resultNormalized,
        public ?CarbonImmutable $receivedDate,
        public ?CarbonImmutable $completedAt,
        public ?string $categoryRaw,
        public ?string $categoryNormalized,
        public array $categoryPath = [],
        public array $events = [],
        public array $documents = [],
        public array $parties = [],
    ) {}

    public function isTransferred(): bool
    {
        return $this->resultNormalized === 'transferred_by_jurisdiction'
            || $this->dispositionType === 'transferred_by_jurisdiction'
            || $this->courtInstanceStatusNormalized === 'transferred';
    }
}
