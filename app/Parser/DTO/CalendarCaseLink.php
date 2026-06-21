<?php

namespace App\Parser\DTO;

use Carbon\CarbonImmutable;

final readonly class CalendarCaseLink
{
    public function __construct(
        public string $url,
        public string $caseNumber,
        public ?string $caseUid,
        public ?string $externalCaseId,
        public ?string $caseTypeId,
        public CarbonImmutable $scheduledDate,
        public ?string $scheduledTime = null,
    ) {}
}
