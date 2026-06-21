<?php

namespace App\Parser\Services;

use App\Models\Parser\CaseDocument;
use App\Models\Parser\CaseEvent;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\CaseParty;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCase;
use App\Models\Parser\RawPage;
use App\Parser\DTO\ParsedCaseInstance;
use App\Parser\DTO\UpsertResult;
use Carbon\CarbonImmutable;

class CaseUpsertService
{
    public function upsert(Court $court, ParsedCaseInstance $parsed, ?CarbonImmutable $windowFrom = null, ?CarbonImmutable $windowTo = null, bool $persistOutOfWindow = false, ?RawPage $rawPage = null): UpsertResult
    {
        $trainingCandidate = $this->isTrainingCandidate($parsed, $windowFrom, $windowTo);

        if (! $trainingCandidate && ! $persistOutOfWindow) {
            return new UpsertResult(false, false, false, 0);
        }

        $sourceUrlHash = hash('sha256', $parsed->sourceUrl);
        $instance = $this->findCaseInstance($court, $parsed, $sourceUrlHash);
        $normalizedNumber = $parsed->normalizedCaseNumber ?? hash('sha256', $parsed->sourceUrl);
        $case = $instance?->courtCase;

        if ($case === null) {
            $case = CourtCase::query()->firstOrNew([
                'primary_court_id' => $court->id,
                'normalized_case_number' => $normalizedNumber,
            ]);
        }

        $createdCase = ! $case->exists;

        $case->fill([
            'normalized_case_number' => $normalizedNumber,
            'primary_court_id' => $court->id,
            'category_raw' => $parsed->categoryRaw,
            'category_normalized' => $parsed->categoryNormalized,
            'proceeding_type' => $parsed->proceedingType,
            'received_date' => $parsed->receivedDate?->toDateString(),
            'final_observed_date' => $parsed->completedAt?->toDateString() ?? $this->lastObservedDate($parsed)?->toDateString(),
            'observation_window_from' => $windowFrom?->toDateString(),
            'observation_window_to' => $windowTo?->toDateString(),
            'is_training_candidate' => $trainingCandidate,
            'discovered_via' => 'hearing_calendar',
        ])->save();

        $instance ??= new CaseInstance;
        $instance->fill([
            'case_id' => $case->id,
            'court_id' => $court->id,
            'raw_page_id' => $rawPage?->id,
            'source_type' => 'sudrf',
            'source_url' => $parsed->sourceUrl,
            'source_url_hash' => $sourceUrlHash,
            'external_case_number' => $parsed->caseNumber,
            'case_uid' => $parsed->caseUid,
            'external_case_id' => $parsed->externalCaseId,
            'instance_level' => $parsed->instanceLevel,
            'status_raw' => $parsed->statusRaw,
            'status_normalized' => $parsed->statusNormalized,
            'result_raw' => $parsed->resultRaw,
            'result_normalized' => $parsed->resultNormalized,
            'started_at' => $parsed->receivedDate?->toDateString(),
            'completed_at' => $parsed->completedAt?->toDateString(),
            'category_raw' => $parsed->categoryRaw,
            'category_normalized' => $parsed->categoryNormalized,
        ])->save();

        $newEvents = 0;
        foreach ($parsed->events as $event) {
            $model = CaseEvent::query()->firstOrCreate(
                ['case_instance_id' => $instance->id, 'event_fingerprint' => $event->fingerprint()],
                [
                    'event_date' => $event->eventDate?->toDateString(),
                    'event_order' => $event->order,
                    'event_type_raw' => $event->eventTypeRaw,
                    'event_type_normalized' => $event->eventTypeNormalized,
                    'event_result_raw' => $event->eventResultRaw,
                    'event_result_normalized' => $event->eventResultNormalized,
                    'source_url' => $event->sourceUrl,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $newEvents++;
            }
        }

        foreach ($parsed->documents as $document) {
            CaseDocument::query()->firstOrCreate(
                ['case_instance_id' => $instance->id, 'document_fingerprint' => $document->fingerprint()],
                [
                    'document_type_raw' => $document->documentTypeRaw,
                    'document_type_normalized' => $document->documentTypeNormalized,
                    'document_number' => $document->documentNumber,
                    'document_date' => $document->documentDate?->toDateString(),
                    'document_kind' => $document->documentKind,
                    'source_url' => $document->sourceUrl,
                ],
            );
        }

        $instance->parties()->delete();
        foreach ($parsed->parties as $party) {
            CaseParty::query()->create([
                'case_instance_id' => $instance->id,
                'role' => $party->role,
                'party_type' => $party->partyType,
                'source_role' => $party->sourceRole,
                'confidence' => $party->confidence,
            ]);
        }

        return new UpsertResult(true, $createdCase, $trainingCandidate, $newEvents, $case, $instance);
    }

    private function findCaseInstance(Court $court, ParsedCaseInstance $parsed, string $sourceUrlHash): ?CaseInstance
    {
        if ($parsed->caseUid !== null) {
            $byUid = CaseInstance::query()->where('court_id', $court->id)->where('case_uid', $parsed->caseUid)->first();

            if ($byUid !== null) {
                return $byUid;
            }
        }

        return CaseInstance::query()->where('court_id', $court->id)->where('source_url_hash', $sourceUrlHash)->first();
    }

    private function isTrainingCandidate(ParsedCaseInstance $parsed, ?CarbonImmutable $windowFrom, ?CarbonImmutable $windowTo): bool
    {
        if ($windowFrom === null || $windowTo === null) {
            return false;
        }

        if ($parsed->receivedDate === null || $parsed->completedAt === null) {
            return false;
        }

        if ($parsed->receivedDate->lt($windowFrom->startOfDay())) {
            return false;
        }

        if ($parsed->completedAt->gt($windowTo->endOfDay())) {
            return false;
        }

        return true;
    }

    private function lastObservedDate(ParsedCaseInstance $parsed): ?CarbonImmutable
    {
        $last = $parsed->receivedDate;

        foreach ($parsed->events as $event) {
            if ($event->eventDate !== null && ($last === null || $event->eventDate->gt($last))) {
                $last = $event->eventDate;
            }
        }

        return $last;
    }
}
