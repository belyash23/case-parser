<?php

namespace App\Parser\DTO;

use App\Models\Parser\CaseInstance;
use App\Models\Parser\CourtCase;

final readonly class UpsertResult
{
    public function __construct(
        public bool $persisted,
        public bool $createdCase,
        public bool $trainingCandidate,
        public int $newEventsCount,
        public ?CourtCase $case = null,
        public ?CaseInstance $caseInstance = null,
    ) {}
}
