<?php

namespace Tests\Unit;

use App\Models\Parser\Court;
use App\Parser\DTO\ParsedCaseInstance;
use App\Parser\Services\CaseUpsertService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CaseUpsertServiceTest extends TestCase
{
    public function test_it_does_not_persist_case_outside_training_window_by_default(): void
    {
        $result = (new CaseUpsertService)->upsert(
            court: new Court,
            parsed: $this->parsedCase(receivedDate: '2024-12-31', completedAt: '2025-02-20'),
            windowFrom: CarbonImmutable::parse('2025-01-01'),
            windowTo: CarbonImmutable::parse('2025-12-31'),
        );

        $this->assertFalse($result->persisted);
        $this->assertFalse($result->trainingCandidate);
    }

    public function test_it_does_not_treat_active_case_as_training_candidate(): void
    {
        $result = (new CaseUpsertService)->upsert(
            court: new Court,
            parsed: $this->parsedCase(receivedDate: '2025-01-10', completedAt: null),
            windowFrom: CarbonImmutable::parse('2025-01-01'),
            windowTo: CarbonImmutable::parse('2025-12-31'),
        );

        $this->assertFalse($result->persisted);
        $this->assertFalse($result->trainingCandidate);
    }

    public function test_it_requires_observation_window_for_training_candidate(): void
    {
        $result = (new CaseUpsertService)->upsert(
            court: new Court,
            parsed: $this->parsedCase(receivedDate: '2025-01-10', completedAt: '2025-02-20'),
        );

        $this->assertFalse($result->persisted);
        $this->assertFalse($result->trainingCandidate);
    }

    private function parsedCase(string $receivedDate, ?string $completedAt): ParsedCaseInstance
    {
        return new ParsedCaseInstance(
            sourceUrl: 'https://industrialnyy--udm.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=100&case_uid=uid-100&delo_id=1540005',
            caseNumber: '2-100/2025',
            normalizedCaseNumber: '2-100/2025',
            caseUid: 'uid-100',
            externalCaseId: '100',
            proceedingType: 'civil_first',
            instanceLevel: 'first',
            statusRaw: $completedAt !== null ? 'completed' : 'active',
            statusNormalized: $completedAt !== null ? 'completed' : 'active',
            resultRaw: null,
            resultNormalized: null,
            receivedDate: CarbonImmutable::parse($receivedDate),
            completedAt: $completedAt !== null ? CarbonImmutable::parse($completedAt) : null,
            categoryRaw: null,
            categoryNormalized: null,
        );
    }
}
