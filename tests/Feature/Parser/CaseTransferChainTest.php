<?php

namespace Tests\Feature\Parser;

use App\Models\Parser\CaseChainLink;
use App\Models\Parser\Court;
use App\Parser\DTO\ParsedCaseInstance;
use App\Parser\Services\CaseUpsertService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseTransferChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_transferred_case_to_continuation_in_another_court(): void
    {
        $sourceCourt = Court::query()->create([
            'name' => 'Source court',
            'base_url' => 'https://source.sudrf.ru',
        ]);
        $targetCourt = Court::query()->create([
            'name' => 'Target court',
            'base_url' => 'https://target.sudrf.ru',
        ]);
        $service = new CaseUpsertService;
        $windowFrom = CarbonImmutable::parse('2026-01-01');
        $windowTo = CarbonImmutable::parse('2026-12-31');

        $sourceResult = $service->upsert(
            court: $sourceCourt,
            parsed: $this->parsedCase(
                sourceUrl: 'https://source.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=1&case_uid=source-uid&delo_id=1540005',
                caseNumber: '2-2255/2026',
                aliases: ['2-2255/2026', 'М-1266/2026'],
                receivedDate: '2026-05-18',
                completedAt: '2026-06-19',
                disputeStatus: 'transferred',
                courtStatus: 'transferred',
                dispositionType: 'transferred_by_jurisdiction',
            ),
            windowFrom: $windowFrom,
            windowTo: $windowTo,
        );

        $this->assertTrue($sourceResult->persisted);
        $this->assertFalse($sourceResult->trainingCandidate);
        $this->assertSame('transfer_pending', $sourceResult->case?->refresh()->chain_status);
        $this->assertDatabaseHas('case_chain_links', [
            'source_instance_id' => $sourceResult->instance?->id,
            'target_instance_id' => null,
            'status' => 'pending',
        ]);

        $targetResult = $service->upsert(
            court: $targetCourt,
            parsed: $this->parsedCase(
                sourceUrl: 'https://target.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=2&case_uid=target-uid&delo_id=1540005',
                caseNumber: '2-3010/2026',
                aliases: ['2-3010/2026', 'М-1266/2026'],
                receivedDate: '2026-06-24',
                completedAt: '2026-08-10',
                disputeStatus: 'resolved',
                courtStatus: 'closed',
                dispositionType: 'satisfied',
            ),
            windowFrom: $windowFrom,
            windowTo: $windowTo,
        );

        $this->assertTrue($targetResult->persisted);
        $this->assertTrue($targetResult->trainingCandidate);
        $this->assertSame($sourceResult->case?->id, $targetResult->instance?->refresh()->case_id);
        $this->assertSame('chain_complete', $sourceResult->case?->refresh()->chain_status);
        $this->assertSame('resolved', $sourceResult->case?->refresh()->dispute_status);
        $this->assertSame('2026-05-18', $sourceResult->case?->refresh()->received_date?->toDateString());
        $this->assertSame('2026-08-10', $sourceResult->case?->refresh()->final_observed_date?->toDateString());

        $link = CaseChainLink::query()->first();
        $this->assertSame($targetResult->instance?->id, $link?->target_instance_id);
        $this->assertSame('resolved', $link?->status);
        $this->assertSame('case_number_alias+transfer_date_window', $link?->matched_by);
    }

    /** @param array<int, string> $aliases */
    private function parsedCase(string $sourceUrl, string $caseNumber, array $aliases, string $receivedDate, string $completedAt, string $disputeStatus, string $courtStatus, string $dispositionType): ParsedCaseInstance
    {
        return new ParsedCaseInstance(
            sourceUrl: $sourceUrl,
            caseNumber: $caseNumber,
            normalizedCaseNumber: $caseNumber,
            normalizedCaseNumberAliases: $aliases,
            caseUid: str_contains($sourceUrl, 'source') ? 'source-uid' : 'target-uid',
            externalCaseId: str_contains($sourceUrl, 'source') ? '1' : '2',
            sourceCaseTypeId: '1540005',
            caseType: 'civil',
            instanceLevel: 'first',
            courtInstanceStatusRaw: $courtStatus,
            courtInstanceStatusNormalized: $courtStatus,
            disputeStatusNormalized: $disputeStatus,
            dispositionType: $dispositionType,
            resultRaw: $dispositionType,
            resultNormalized: $dispositionType,
            receivedDate: CarbonImmutable::parse($receivedDate),
            completedAt: CarbonImmutable::parse($completedAt),
            categoryRaw: 'consumer protection',
            categoryNormalized: 'consumer_protection',
            categoryPath: ['consumer protection', 'construction services'],
        );
    }
}
