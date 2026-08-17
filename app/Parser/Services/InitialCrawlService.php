<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCrawlState;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserRun;
use App\Parser\Adapters\SudrfCourtAdapter;
use App\Parser\DTO\CalendarCaseLink;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Normalizers\CaseNumberNormalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class InitialCrawlService
{
    public function __construct(
        private readonly CourtCrawler $crawler,
        private readonly SudrfCourtAdapter $adapter,
        private readonly CaseNumberNormalizer $caseNumberNormalizer,
        private readonly SudrfSourceGuard $sourceGuard,
        private readonly ParserSettings $settings,
    ) {}

    /**
     * @param  Collection<int, Court>  $courts
     * @param  array<string, mixed>  $selection
     */
    public function createCampaign(CarbonImmutable $from, CarbonImmutable $to, Collection $courts, array $selection = []): CrawlCampaign
    {
        if ($courts->isEmpty()) {
            throw new InvalidArgumentException('At least one court is required for an initial crawl.');
        }

        if ($from->gt($to)) {
            throw new InvalidArgumentException('The initial crawl start date must not be after its end date.');
        }

        return DB::transaction(function () use ($from, $to, $courts, $selection): CrawlCampaign {
            $courtIds = $courts->pluck('id')->map(fn (mixed $courtId): int => (int) $courtId)->values()->all();
            $firstMonth = $to->startOfMonth();
            $campaign = CrawlCampaign::query()->create([
                'mode' => CrawlCampaignMode::Initial,
                'window_from' => $from->toDateString(),
                'window_to' => $to->toDateString(),
                'settings_json' => [
                    ...$selection,
                    'court_ids' => $courtIds,
                    'current_month' => $firstMonth->toDateString(),
                    'traversal_order' => 'month_desc,court_order,date_desc',
                    'case_deduplication' => 'court_id+normalized_case_number',
                    'auto_resume' => true,
                ],
            ]);

            $this->seedMonth($campaign, $firstMonth, $courtIds);

            return $campaign->refresh();
        }, 5);
    }

    public function run(CrawlCampaign $campaign, ParserRun $run, ?Closure $progress = null, int $timeLimitSeconds = 0): bool
    {
        $this->assertInitialCampaign($campaign);
        $this->resetInterruptedWork($campaign);
        $startedAt = microtime(true);
        $shouldStop = fn (): bool => ($timeLimitSeconds > 0 && microtime(true) - $startedAt >= $timeLimitSeconds)
            || $campaign->refresh()->status !== CrawlCampaignStatus::Running;

        while (true) {
            if ($shouldStop()) {
                return false;
            }
            $workItem = $this->nextMonthWorkItem($campaign);

            if (! $workItem instanceof CrawlWorkItem) {
                if (! $this->advanceToPreviousMonth($campaign)) {
                    return true;
                }

                continue;
            }

            if (! $this->processMonthWorkItem($campaign, $workItem, $run, $shouldStop)) {
                return false;
            }
            $progress?->__invoke(sprintf(
                'Completed court=%d month=%s; requests=%d, links=%d, errors=%d.',
                $workItem->court_id,
                $workItem->target_date?->format('Y-m'),
                $run->refresh()->total_requests,
                $run->calendar_case_links_count,
                $run->error_count,
            ));
        }
    }

    private function processMonthWorkItem(CrawlCampaign $campaign, CrawlWorkItem $workItem, ParserRun $run, Closure $shouldStop): bool
    {
        $court = Court::query()->findOrFail($workItem->court_id);
        $payload = $workItem->payload_json ?? [];
        $periodFrom = CarbonImmutable::parse((string) $payload['period_from'])->startOfDay();
        $cursorDate = CarbonImmutable::parse((string) ($payload['cursor_date'] ?? $payload['period_to']))->startOfDay();

        $workItem->forceFill([
            'status' => CrawlWorkStatus::Running,
            'started_at' => $workItem->started_at ?? now(),
            'finished_at' => null,
            'attempts' => $workItem->attempts + 1,
            'last_error' => null,
        ])->save();

        while ($cursorDate->gte($periodFrom)) {
            if ($shouldStop()) {
                $workItem->forceFill(['status' => CrawlWorkStatus::Pending])->save();

                return false;
            }

            $this->heartbeat($campaign);
            $requestsBefore = (int) $run->refresh()->total_requests;

            try {
                $links = $this->crawler->crawlCalendarDate($court, $cursorDate, $run);
                $newCaseNumbers = 0;

                foreach ($links as $link) {
                    if ($shouldStop()) {
                        $workItem->forceFill(['status' => CrawlWorkStatus::Pending])->save();

                        return false;
                    }

                    if ($this->processCaseLink($campaign, $court, $link, $run)) {
                        $newCaseNumbers++;
                    }

                    $this->heartbeat($campaign);
                }

                $nextCursorDate = $cursorDate->subDay();
                $requestsUsed = $this->recordRequestUsage($campaign, $workItem, $run, $requestsBefore);
                $payload['cursor_date'] = $nextCursorDate->toDateString();
                $workItem->forceFill(['payload_json' => $payload])->save();
                $this->rememberCourtProgress($court, $nextCursorDate, count($links), $newCaseNumbers, $requestsUsed, true);
                $cursorDate = $nextCursorDate;
            } catch (Throwable $exception) {
                $requestsUsed = $this->recordRequestUsage($campaign, $workItem, $run, $requestsBefore);
                $workItem->forceFill([
                    'status' => CrawlWorkStatus::Failed,
                    'last_error' => $exception->getMessage(),
                ])->save();
                $this->rememberCourtProgress($court, $cursorDate, 0, 0, $requestsUsed, false);

                throw $exception;
            }
        }

        $workItem->forceFill([
            'status' => CrawlWorkStatus::Completed,
            'finished_at' => now(),
            'last_error' => null,
        ])->save();

        $court->forceFill([
            'last_checked_at' => now(),
            'last_successful_crawl_at' => now(),
            'status' => 'active',
        ])->save();

        return true;
    }

    private function processCaseLink(CrawlCampaign $campaign, Court $court, CalendarCaseLink $link, ParserRun $run): bool
    {
        if (! $this->adapter->isCivilFirstInstance($link)) {
            return false;
        }

        $normalizedCaseNumber = $this->caseNumberNormalizer->firstInstanceNumber($link->caseNumber)
            ?? $this->caseNumberNormalizer->normalize($link->caseNumber)
            ?? hash('sha256', $link->url);
        $deduplicationKey = hash('sha256', 'case-card:'.$court->id.':'.$normalizedCaseNumber);
        $caseWorkItem = CrawlWorkItem::query()->firstOrCreate(
            [
                'crawl_campaign_id' => $campaign->id,
                'deduplication_key' => $deduplicationKey,
            ],
            [
                'court_id' => $court->id,
                'work_type' => CrawlWorkType::CaseCard,
                'status' => CrawlWorkStatus::Pending,
                'target_date' => $link->scheduledDate->toDateString(),
                'priority' => 100,
                'payload_json' => [
                    'case_number' => $link->caseNumber,
                    'normalized_case_number' => $normalizedCaseNumber,
                    'url' => $link->url,
                    'case_uid' => $link->caseUid,
                    'external_case_id' => $link->externalCaseId,
                ],
            ],
        );
        $wasDiscoveredNow = $caseWorkItem->wasRecentlyCreated;
        $maximumAttempts = max(1, $this->settings->current()->initial_maximum_case_attempts);

        if ($caseWorkItem->status === CrawlWorkStatus::Completed || $caseWorkItem->attempts >= $maximumAttempts) {
            return $wasDiscoveredNow;
        }

        $caseWorkItem->forceFill([
            'status' => CrawlWorkStatus::Running,
            'started_at' => $caseWorkItem->started_at ?? now(),
            'finished_at' => null,
            'attempts' => $caseWorkItem->attempts + 1,
            'last_error' => null,
        ])->save();

        try {
            $parsedSuccessfully = $this->crawler->crawlCase(
                $court,
                $link->url,
                $run,
                $campaign->window_from !== null ? CarbonImmutable::parse($campaign->window_from->toDateString()) : null,
                $campaign->window_to !== null ? CarbonImmutable::parse($campaign->window_to->toDateString()) : null,
                persistOutOfWindow: true,
            );

            if ($parsedSuccessfully) {
                $caseWorkItem->forceFill([
                    'status' => CrawlWorkStatus::Completed,
                    'finished_at' => now(),
                    'last_error' => null,
                ])->save();
            } else {
                $this->sourceGuard->ensureCircuitAllowsRequests();
                $attemptsExhausted = $caseWorkItem->attempts >= $maximumAttempts;
                $caseWorkItem->forceFill([
                    'status' => $attemptsExhausted ? CrawlWorkStatus::Failed : CrawlWorkStatus::Pending,
                    'finished_at' => $attemptsExhausted ? now() : null,
                    'last_error' => 'The case card could not be fetched or parsed.',
                ])->save();

                if (! $attemptsExhausted) {
                    throw new \RuntimeException('The case card could not be fetched or parsed.');
                }
            }
        } catch (SourceCircuitOpenException|ConnectionException $exception) {
            $caseWorkItem->forceFill([
                'status' => CrawlWorkStatus::Pending,
                'attempts' => max(0, $caseWorkItem->attempts - 1),
                'finished_at' => null,
                'last_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } catch (Throwable $exception) {
            $caseWorkItem->forceFill([
                'status' => CrawlWorkStatus::Pending,
                'finished_at' => null,
                'last_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return $wasDiscoveredNow;
    }

    private function nextMonthWorkItem(CrawlCampaign $campaign): ?CrawlWorkItem
    {
        return CrawlWorkItem::query()
            ->whereBelongsTo($campaign, 'campaign')
            ->where('work_type', CrawlWorkType::InitialMonth)
            ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
            ->latest('target_date')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    private function advanceToPreviousMonth(CrawlCampaign $campaign): bool
    {
        return DB::transaction(function () use ($campaign): bool {
            $lockedCampaign = CrawlCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $hasIncompleteMonth = CrawlWorkItem::query()
                ->whereBelongsTo($lockedCampaign, 'campaign')
                ->where('work_type', CrawlWorkType::InitialMonth)
                ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
                ->exists();

            if ($hasIncompleteMonth) {
                return true;
            }

            $settings = $lockedCampaign->settings_json ?? [];
            $currentMonth = CarbonImmutable::parse((string) $settings['current_month'])->startOfMonth();
            $previousMonth = $currentMonth->subMonth()->startOfMonth();
            $windowFrom = CarbonImmutable::parse($lockedCampaign->window_from?->toDateString() ?? throw new InvalidArgumentException('Campaign window_from is required.'));

            if ($previousMonth->endOfMonth()->lt($windowFrom)) {
                return false;
            }

            $settings['current_month'] = $previousMonth->toDateString();
            $lockedCampaign->forceFill([
                'settings_json' => $settings,
                'last_heartbeat_at' => now(),
            ])->save();
            $this->seedMonth($lockedCampaign, $previousMonth, $this->courtIds($lockedCampaign));
            $campaign->setRawAttributes($lockedCampaign->getAttributes(), true);

            return true;
        }, 5);
    }

    /** @param array<int, int> $courtIds */
    private function seedMonth(CrawlCampaign $campaign, CarbonImmutable $month, array $courtIds): void
    {
        $windowFrom = CarbonImmutable::parse($campaign->window_from?->toDateString() ?? throw new InvalidArgumentException('Campaign window_from is required.'));
        $windowTo = CarbonImmutable::parse($campaign->window_to?->toDateString() ?? throw new InvalidArgumentException('Campaign window_to is required.'));
        $periodFrom = $month->startOfMonth()->max($windowFrom);
        $periodTo = $month->endOfMonth()->min($windowTo);
        $timestamp = now();
        $rows = [];

        foreach ($courtIds as $index => $courtId) {
            $rows[] = [
                'crawl_campaign_id' => $campaign->id,
                'court_id' => $courtId,
                'work_type' => CrawlWorkType::InitialMonth->value,
                'status' => CrawlWorkStatus::Pending->value,
                'deduplication_key' => hash('sha256', 'initial-month:'.$courtId.':'.$month->format('Y-m')),
                'target_date' => $month->toDateString(),
                'priority' => $index + 1,
                'available_at' => $timestamp,
                'attempts' => 0,
                'request_cost' => 0,
                'payload_json' => json_encode([
                    'period_from' => $periodFrom->toDateString(),
                    'period_to' => $periodTo->toDateString(),
                    'cursor_date' => $periodTo->toDateString(),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        collect($rows)->chunk(500)->each(
            fn (Collection $chunk) => CrawlWorkItem::query()->insertOrIgnore($chunk->all()),
        );
    }

    private function resetInterruptedWork(CrawlCampaign $campaign): void
    {
        CrawlWorkItem::query()
            ->whereBelongsTo($campaign, 'campaign')
            ->where('status', CrawlWorkStatus::Running)
            ->update([
                'status' => CrawlWorkStatus::Pending,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function recordRequestUsage(CrawlCampaign $campaign, CrawlWorkItem $workItem, ParserRun $run, int $requestsBefore): int
    {
        $requestsUsed = max(0, (int) $run->refresh()->total_requests - $requestsBefore);

        if ($requestsUsed > 0) {
            $workItem->increment('request_cost', $requestsUsed);
            $campaign->increment('requests_used', $requestsUsed);
        }

        $campaign->forceFill(['last_heartbeat_at' => now()])->save();

        return $requestsUsed;
    }

    private function heartbeat(CrawlCampaign $campaign): void
    {
        if ($campaign->last_heartbeat_at !== null && $campaign->last_heartbeat_at->gt(now()->subMinute())) {
            return;
        }

        $campaign->forceFill(['last_heartbeat_at' => now()])->save();
    }

    private function rememberCourtProgress(Court $court, CarbonImmutable $nextCursorDate, int $caseLinks, int $newCaseNumbers, int $requestsUsed, bool $successful): void
    {
        $state = CourtCrawlState::query()->firstOrNew(['court_id' => $court->id]);
        $statistics = $state->stats_json ?? [];
        $statistics['initial_calendar_days'] = (int) ($statistics['initial_calendar_days'] ?? 0) + ($successful ? 1 : 0);
        $statistics['initial_case_links'] = (int) ($statistics['initial_case_links'] ?? 0) + $caseLinks;
        $statistics['initial_unique_cases'] = (int) ($statistics['initial_unique_cases'] ?? 0) + $newCaseNumbers;
        $statistics['initial_requests'] = (int) ($statistics['initial_requests'] ?? 0) + $requestsUsed;

        $state->forceFill([
            'initial_cursor_date' => $nextCursorDate->toDateString(),
            'last_attempted_at' => now(),
            'last_successful_at' => $successful ? now() : $state->last_successful_at,
            'stats_json' => $statistics,
        ])->save();
    }

    /** @return array<int, int> */
    private function courtIds(CrawlCampaign $campaign): array
    {
        return collect($campaign->settings_json['court_ids'] ?? [])
            ->map(fn (mixed $courtId): int => (int) $courtId)
            ->filter(fn (int $courtId): bool => $courtId > 0)
            ->values()
            ->all();
    }

    private function assertInitialCampaign(CrawlCampaign $campaign): void
    {
        if ($campaign->mode !== CrawlCampaignMode::Initial || $campaign->window_from === null || $campaign->window_to === null) {
            throw new InvalidArgumentException('A valid initial crawl campaign with a date window is required.');
        }
    }
}
