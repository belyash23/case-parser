<?php

namespace App\Parser\Services;

use App\Models\Parser\CaseChainLink;
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
use Carbon\CarbonInterface;

class CaseUpsertService
{
    private const TRANSFER_LINK_TYPE = 'transferred_by_jurisdiction';

    private const JOINED_LINK_TYPE = 'joined_to_another_case';

    private const TRANSFER_MATCH_THRESHOLD = 90;

    public function upsert(Court $court, ParsedCaseInstance $parsed, ?CarbonImmutable $windowFrom = null, ?CarbonImmutable $windowTo = null, bool $persistOutOfWindow = false, ?RawPage $rawPage = null): UpsertResult
    {
        $trainingCandidate = $this->isTrainingCandidate($parsed, $windowFrom, $windowTo);

        if (! $trainingCandidate && ! $persistOutOfWindow && ! $parsed->isTransferred() && ! $parsed->isJoinedToAnotherCase()) {
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
        $categoryFields = $this->categoryFields($parsed->categoryPath);

        $case->fill([
            'normalized_case_number' => $normalizedNumber,
            'normalized_case_number_aliases_json' => $this->caseNumberAliases($parsed),
            'primary_court_id' => $court->id,
            'category_raw' => $parsed->categoryRaw,
            'category_normalized' => $parsed->categoryNormalized,
            ...$categoryFields,
            'case_type' => $parsed->caseType,
            'dispute_status' => $parsed->disputeStatusNormalized ?? 'unknown',
            'final_disposition_type' => $parsed->dispositionType,
            'chain_status' => match (true) {
                $parsed->isTransferred() => 'transfer_pending',
                $parsed->isJoinedToAnotherCase() => 'merge_pending',
                default => 'single_court',
            },
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
            'normalized_case_number_aliases_json' => $this->caseNumberAliases($parsed),
            'case_uid' => $parsed->caseUid,
            'external_case_id' => $parsed->externalCaseId,
            'source_case_type_id' => $parsed->sourceCaseTypeId,
            'case_type' => $parsed->caseType,
            'instance_level' => $parsed->instanceLevel,
            'court_instance_status_raw' => $parsed->courtInstanceStatusRaw,
            'court_instance_status_normalized' => $parsed->courtInstanceStatusNormalized,
            'dispute_status_normalized' => $parsed->disputeStatusNormalized,
            'disposition_type' => $parsed->dispositionType,
            'result_raw' => $parsed->resultRaw,
            'result_normalized' => $parsed->resultNormalized,
            'started_at' => $parsed->receivedDate?->toDateString(),
            'completed_at' => $parsed->completedAt?->toDateString(),
            'category_raw' => $parsed->categoryRaw,
            'category_normalized' => $parsed->categoryNormalized,
            ...$categoryFields,
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
                'role_group' => $party->roleGroup,
                'party_type' => $party->partyType,
                'is_hidden' => $party->isHidden,
                'source_role' => $party->sourceRole,
                'confidence' => $party->confidence,
            ]);
        }

        if ($parsed->isTransferred()) {
            $this->rememberTransferLink($instance, $parsed);
        }

        if ($parsed->isJoinedToAnotherCase()) {
            $this->rememberJoinedLink($instance, $parsed);
        }

        $this->resolvePendingTransfersAgainst($instance);

        if ($parsed->isTransferred()) {
            $this->resolveOutgoingTransferFrom($instance);
        }

        $case = $instance->refresh()->courtCase;
        $this->refreshCourtCaseChainState($case);
        $case->refresh();

        return new UpsertResult(true, $createdCase, (bool) $case->is_training_candidate, $newEvents, $case, $instance);
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
        if ($parsed->disputeStatusNormalized !== 'resolved') {
            return false;
        }

        if ($parsed->isTransferred() || $parsed->isJoinedToAnotherCase()) {
            return false;
        }

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

    /**
     * @param  array<int, string>  $categoryPath
     * @return array<string, mixed>
     */
    private function categoryFields(array $categoryPath): array
    {
        return [
            'category_path_json' => $categoryPath !== [] ? $categoryPath : null,
            'category_level_1' => $categoryPath[0] ?? null,
            'category_level_2' => $categoryPath[1] ?? null,
            'category_level_3' => $categoryPath[2] ?? null,
            'category_level_4' => $categoryPath[3] ?? null,
            'category_leaf' => $categoryPath !== [] ? $categoryPath[array_key_last($categoryPath)] : null,
        ];
    }

    /** @return array<int, string>|null */
    private function caseNumberAliases(ParsedCaseInstance $parsed): ?array
    {
        $aliases = collect($parsed->normalizedCaseNumberAliases)
            ->when($parsed->normalizedCaseNumber !== null, fn ($collection) => $collection->push($parsed->normalizedCaseNumber))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $aliases !== [] ? $aliases : null;
    }

    private function rememberTransferLink(CaseInstance $instance, ParsedCaseInstance $parsed): void
    {
        $link = CaseChainLink::query()
            ->where('source_instance_id', $instance->id)
            ->whereNull('target_instance_id')
            ->where('link_type', self::TRANSFER_LINK_TYPE)
            ->firstOrNew([
                'source_instance_id' => $instance->id,
                'target_instance_id' => null,
                'link_type' => self::TRANSFER_LINK_TYPE,
            ]);

        $link->fill([
            'status' => 'pending',
            'matched_by' => null,
            'confidence' => 0.8,
            'evidence_json' => [
                'source_url' => $parsed->sourceUrl,
                'case_uid' => $parsed->caseUid,
                'external_case_id' => $parsed->externalCaseId,
                'case_number' => $parsed->caseNumber,
                'normalized_case_number_aliases' => $this->caseNumberAliases($parsed) ?? [],
                'result_raw' => $parsed->resultRaw,
                'result_normalized' => $parsed->resultNormalized,
                'transfer_event_dates' => collect($parsed->events)
                    ->filter(fn ($event): bool => $event->eventTypeNormalized === 'case_transferred_to_another_court'
                        || $event->eventResultNormalized === self::TRANSFER_LINK_TYPE)
                    ->map(fn ($event): ?string => $event->eventDate?->toDateString())
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ])->save();
    }

    private function rememberJoinedLink(CaseInstance $instance, ParsedCaseInstance $parsed): void
    {
        $link = CaseChainLink::query()
            ->where('source_instance_id', $instance->id)
            ->whereNull('target_instance_id')
            ->where('link_type', self::JOINED_LINK_TYPE)
            ->firstOrNew([
                'source_instance_id' => $instance->id,
                'target_instance_id' => null,
                'link_type' => self::JOINED_LINK_TYPE,
            ]);

        $link->fill([
            'status' => 'pending',
            'matched_by' => null,
            'confidence' => 1,
            'evidence_json' => [
                'source_url' => $parsed->sourceUrl,
                'case_uid' => $parsed->caseUid,
                'external_case_id' => $parsed->externalCaseId,
                'case_number' => $parsed->caseNumber,
                'result_raw' => $parsed->resultRaw,
                'result_normalized' => $parsed->resultNormalized,
            ],
        ])->save();
    }

    private function resolvePendingTransfersAgainst(CaseInstance $target): void
    {
        CaseChainLink::query()
            ->with(['sourceInstance.courtCase', 'sourceInstance.court'])
            ->where('link_type', self::TRANSFER_LINK_TYPE)
            ->where('status', 'pending')
            ->whereNull('target_instance_id')
            ->where('source_instance_id', '!=', $target->id)
            ->get()
            ->each(function (CaseChainLink $link) use ($target): void {
                $source = $link->sourceInstance;

                if (! $source instanceof CaseInstance) {
                    return;
                }

                $match = $this->transferMatch($source, $target);

                if ($match['score'] < self::TRANSFER_MATCH_THRESHOLD) {
                    return;
                }

                $this->attachTransferTarget($link, $target, $match);
            });
    }

    private function resolveOutgoingTransferFrom(CaseInstance $source): void
    {
        $link = CaseChainLink::query()
            ->where('source_instance_id', $source->id)
            ->where('link_type', self::TRANSFER_LINK_TYPE)
            ->where('status', 'pending')
            ->whereNull('target_instance_id')
            ->first();

        if (! $link instanceof CaseChainLink) {
            return;
        }

        $best = CaseInstance::query()
            ->with(['courtCase', 'court'])
            ->where('id', '!=', $source->id)
            ->where('court_id', '!=', $source->court_id)
            ->get()
            ->map(fn (CaseInstance $candidate): array => ['instance' => $candidate, 'match' => $this->transferMatch($source, $candidate)])
            ->filter(fn (array $candidate): bool => $candidate['match']['score'] >= self::TRANSFER_MATCH_THRESHOLD)
            ->sortByDesc(fn (array $candidate): int => $candidate['match']['score'])
            ->first();

        if ($best === null) {
            return;
        }

        $this->attachTransferTarget($link, $best['instance'], $best['match']);
    }

    /** @return array{score:int, matched_by:string|null, evidence:array<string, mixed>} */
    private function transferMatch(CaseInstance $source, CaseInstance $target): array
    {
        if ($source->id === $target->id || $source->court_id === $target->court_id) {
            return ['score' => 0, 'matched_by' => null, 'evidence' => []];
        }

        if ($source->completed_at !== null && $target->started_at !== null && $target->started_at->lt($source->completed_at->copy()->subDays(14))) {
            return ['score' => 0, 'matched_by' => null, 'evidence' => ['reason' => 'target_started_too_early']];
        }

        $score = 0;
        $matchedBy = [];
        $sourceAliases = $this->instanceAliases($source);
        $targetAliases = $this->instanceAliases($target);
        $commonAliases = array_values(array_intersect($sourceAliases, $targetAliases));

        if ($source->case_uid !== null && $source->case_uid === $target->case_uid) {
            $score += 100;
            $matchedBy[] = 'case_uid';
        }

        if ($commonAliases !== []) {
            $score += 70;
            $matchedBy[] = 'case_number_alias';
        }

        if ($source->case_type !== null && $source->case_type === $target->case_type) {
            $score += 15;
        }

        if ($source->category_normalized !== null && $source->category_normalized === $target->category_normalized) {
            $score += 10;
        }

        if ($source->category_leaf !== null && $source->category_leaf === $target->category_leaf) {
            $score += 10;
        }

        if ($source->completed_at !== null && $target->started_at !== null) {
            $days = $source->completed_at->diffInDays($target->started_at, false);

            if ($days >= -14 && $days <= 180) {
                $score += 20;
                $matchedBy[] = 'transfer_date_window';
            } elseif ($days >= -14 && $days <= 365) {
                $score += 10;
                $matchedBy[] = 'wide_transfer_date_window';
            }
        }

        return [
            'score' => $score,
            'matched_by' => $matchedBy !== [] ? implode('+', $matchedBy) : null,
            'evidence' => [
                'score' => $score,
                'common_case_number_aliases' => $commonAliases,
                'source_instance_id' => $source->id,
                'target_instance_id' => $target->id,
                'source_court_id' => $source->court_id,
                'target_court_id' => $target->court_id,
                'source_completed_at' => $source->completed_at?->toDateString(),
                'target_started_at' => $target->started_at?->toDateString(),
            ],
        ];
    }

    /** @return array<int, string> */
    private function instanceAliases(CaseInstance $instance): array
    {
        return collect($instance->normalized_case_number_aliases_json ?? [])
            ->when($instance->external_case_number !== null, fn ($collection) => $collection->push($instance->external_case_number))
            ->map(fn (string $number): string => mb_strtoupper(preg_replace('/\s+/u', '', str_replace(["\u{2116}", 'N'], '', $number)) ?? $number))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{score:int, matched_by:string|null, evidence:array<string, mixed>} $match */
    private function attachTransferTarget(CaseChainLink $link, CaseInstance $target, array $match): void
    {
        $source = $link->sourceInstance()->with('courtCase')->first();

        if (! $source instanceof CaseInstance || ! $source->courtCase instanceof CourtCase) {
            return;
        }

        $canonicalCase = $source->courtCase;
        $targetCase = $target->courtCase;

        if ($targetCase instanceof CourtCase && $targetCase->id !== $canonicalCase->id) {
            CaseInstance::query()
                ->where('case_id', $targetCase->id)
                ->update(['case_id' => $canonicalCase->id]);

            $targetCase->delete();
            $target->refresh();
        }

        $existingEvidence = is_array($link->evidence_json) ? $link->evidence_json : [];
        $link->fill([
            'target_instance_id' => $target->id,
            'status' => 'resolved',
            'matched_by' => $match['matched_by'],
            'confidence' => min(1, $match['score'] / 100),
            'evidence_json' => [
                ...$existingEvidence,
                'match' => $match['evidence'],
            ],
        ])->save();

        $this->refreshCourtCaseChainState($canonicalCase->refresh());
    }

    private function refreshCourtCaseChainState(CourtCase $case): void
    {
        $instances = $case->instances()->with(['outgoingChainLinks'])->get();

        if ($instances->isEmpty()) {
            return;
        }

        $hasTransfer = $instances->contains(fn (CaseInstance $instance): bool => $instance->court_instance_status_normalized === 'transferred');
        $hasPendingTransfer = $instances->contains(fn (CaseInstance $instance): bool => $instance->outgoingChainLinks
            ->where('link_type', self::TRANSFER_LINK_TYPE)
            ->where('status', 'pending')
            ->whereNull('target_instance_id')
            ->isNotEmpty());
        $hasJoinedCase = $instances->contains(fn (CaseInstance $instance): bool => $instance->result_normalized === self::JOINED_LINK_TYPE);
        $hasPendingJoin = $instances->contains(fn (CaseInstance $instance): bool => $instance->outgoingChainLinks
            ->where('link_type', self::JOINED_LINK_TYPE)
            ->where('status', 'pending')
            ->whereNull('target_instance_id')
            ->isNotEmpty());
        $finalInstance = $instances
            ->sortByDesc(fn (CaseInstance $instance): string => $instance->completed_at?->toDateString() ?? $instance->started_at?->toDateString() ?? '')
            ->first();
        $receivedDate = $instances
            ->map(fn (CaseInstance $instance) => $instance->started_at)
            ->filter()
            ->sortBy(fn (CarbonInterface $date): string => $date->toDateString())
            ->first();
        $finalObservedDate = $instances
            ->flatMap(fn (CaseInstance $instance): array => [$instance->completed_at, $instance->started_at])
            ->filter()
            ->sortBy(fn (CarbonInterface $date): string => $date->toDateString())
            ->last();
        $chainStatus = match (true) {
            $hasPendingJoin => 'merge_pending',
            $hasPendingTransfer => 'transfer_pending',
            $hasTransfer || $hasJoinedCase => 'chain_complete',
            default => 'single_court',
        };
        $disputeStatus = match (true) {
            $hasPendingJoin => 'merged',
            $hasPendingTransfer => 'transferred',
            default => $finalInstance?->dispute_status_normalized ?? 'unknown',
        };
        $isTrainingCandidate = $this->isCaseTrainingCandidate($case, $receivedDate, $finalObservedDate, $disputeStatus, $chainStatus);

        $case->forceFill([
            'chain_status' => $chainStatus,
            'dispute_status' => $disputeStatus,
            'final_disposition_type' => match (true) {
                $hasPendingJoin => self::JOINED_LINK_TYPE,
                $hasPendingTransfer => self::TRANSFER_LINK_TYPE,
                default => $finalInstance?->disposition_type,
            },
            'received_date' => $receivedDate?->toDateString(),
            'final_observed_date' => $finalObservedDate?->toDateString(),
            'is_training_candidate' => $isTrainingCandidate,
        ])->save();
    }

    private function isCaseTrainingCandidate(CourtCase $case, ?CarbonInterface $receivedDate, ?CarbonInterface $finalObservedDate, string $disputeStatus, string $chainStatus): bool
    {
        if ($disputeStatus !== 'resolved' || in_array($chainStatus, ['transfer_pending', 'merge_pending'], true)) {
            return false;
        }

        if ($case->observation_window_from === null || $case->observation_window_to === null) {
            return false;
        }

        if ($receivedDate === null || $finalObservedDate === null) {
            return false;
        }

        if ($receivedDate->lt($case->observation_window_from->startOfDay())) {
            return false;
        }

        if ($finalObservedDate->gt($case->observation_window_to->endOfDay())) {
            return false;
        }

        return true;
    }
}
