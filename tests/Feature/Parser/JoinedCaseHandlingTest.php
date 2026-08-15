<?php

namespace Tests\Feature\Parser;

use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCase;
use App\Parser\DTO\ParsedCaseInstance;
use App\Parser\Services\CaseUpsertService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinedCaseHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_joined_case_is_persisted_but_excluded_from_training(): void
    {
        $court = Court::query()->create([
            'name' => 'Leninskiy court',
            'base_url' => 'https://leninskiy.udm.sudrf.ru',
        ]);
        $result = (new CaseUpsertService)->upsert(
            court: $court,
            parsed: $this->joinedCase(),
            windowFrom: CarbonImmutable::parse('2025-01-01'),
            windowTo: CarbonImmutable::parse('2025-12-31'),
        );

        $this->assertTrue($result->persisted);
        $this->assertFalse($result->trainingCandidate);
        $this->assertSame('merged', $result->instance?->dispute_status_normalized);
        $this->assertSame('merge_pending', $result->case?->refresh()->chain_status);
        $this->assertFalse((bool) $result->case?->is_training_candidate);
        $this->assertDatabaseHas('case_chain_links', [
            'source_instance_id' => $result->instance?->id,
            'target_instance_id' => null,
            'link_type' => 'joined_to_another_case',
            'status' => 'pending',
        ]);
    }

    public function test_backfill_reclassifies_previously_stored_joined_case(): void
    {
        $court = Court::query()->create([
            'name' => 'Leninskiy court',
            'base_url' => 'https://leninskiy.udm.sudrf.ru',
        ]);
        $courtCase = CourtCase::query()->create([
            'normalized_case_number' => '2-1324/2025',
            'primary_court_id' => $court->id,
            'dispute_status' => 'resolved',
            'chain_status' => 'single_court',
            'is_training_candidate' => true,
        ]);
        $sourceUrl = 'https://leninskiy.udm.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=1324';
        $instance = CaseInstance::query()->create([
            'case_id' => $courtCase->id,
            'court_id' => $court->id,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'court_instance_status_normalized' => 'closed',
            'dispute_status_normalized' => 'resolved',
            'disposition_type' => 'other',
            'result_raw' => "\u{0414}\u{0435}\u{043b}\u{043e} \u{043f}\u{0440}\u{0438}\u{0441}\u{043e}\u{0435}\u{0434}\u{0438}\u{043d}\u{0435}\u{043d}\u{043e} \u{043a} \u{0434}\u{0440}\u{0443}\u{0433}\u{043e}\u{043c}\u{0443} \u{0434}\u{0435}\u{043b}\u{0443}",
            'result_normalized' => 'other',
        ]);

        $this->artisan('parser:backfill-joined-cases')->assertExitCode(0);

        $this->assertSame('joined_to_another_case', $instance->refresh()->result_normalized);
        $this->assertSame('merged', $instance->dispute_status_normalized);
        $this->assertSame('merge_pending', $courtCase->refresh()->chain_status);
        $this->assertFalse((bool) $courtCase->is_training_candidate);
        $this->assertDatabaseHas('case_chain_links', [
            'source_instance_id' => $instance->id,
            'link_type' => 'joined_to_another_case',
            'status' => 'pending',
        ]);
    }

    private function joinedCase(): ParsedCaseInstance
    {
        return new ParsedCaseInstance(
            sourceUrl: 'https://leninskiy.udm.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=1324&case_uid=joined-uid&delo_id=1540005',
            caseNumber: '2-1324/2025',
            normalizedCaseNumber: '2-1324/2025',
            normalizedCaseNumberAliases: ['2-1324/2025'],
            caseUid: 'joined-uid',
            externalCaseId: '1324',
            sourceCaseTypeId: '1540005',
            caseType: 'civil',
            instanceLevel: 'first',
            courtInstanceStatusRaw: 'closed',
            courtInstanceStatusNormalized: 'closed',
            disputeStatusNormalized: 'merged',
            dispositionType: 'joined_to_another_case',
            resultRaw: "\u{0414}\u{0435}\u{043b}\u{043e} \u{043f}\u{0440}\u{0438}\u{0441}\u{043e}\u{0435}\u{0434}\u{0438}\u{043d}\u{0435}\u{043d}\u{043e} \u{043a} \u{0434}\u{0440}\u{0443}\u{0433}\u{043e}\u{043c}\u{0443} \u{0434}\u{0435}\u{043b}\u{0443}",
            resultNormalized: 'joined_to_another_case',
            receivedDate: CarbonImmutable::parse('2025-01-10'),
            completedAt: CarbonImmutable::parse('2025-02-20'),
            categoryRaw: null,
            categoryNormalized: null,
        );
    }
}
