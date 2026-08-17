<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use App\Enums\Parser\RegularCrawlStopReason;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCrawlState;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserRun;
use App\Parser\Adapters\SudrfCourtAdapter;
use App\Parser\DTO\CalendarCaseLink;
use App\Parser\DTO\RegularCrawlResult;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Normalizers\CaseNumberNormalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class RegularCrawlService
{
    public function __construct(
        private readonly CourtCrawler $crawler,
        private readonly SudrfCourtAdapter $adapter,
        private readonly CaseNumberNormalizer $caseNumberNormalizer,
        private readonly SudrfSourceGuard $sourceGuard,
        private readonly RegularCrawlBudgetAllocator $budgetAllocator,
        private readonly ParserSettings $settings,
    ) {}

    /**
     * @param  Collection<int, Court>  $courts
     * @param  array<string, mixed>  $selection
     */
    public function createCampaign(Collection $courts, array $selection = []): CrawlCampaign
    {
        if ($courts->isEmpty()) {
            throw new InvalidArgumentException('At least one court is required for a regular crawl.');
        }

        return CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'settings_json' => [
                ...$selection,
                'court_ids' => $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'lane_cursor' => 0,
                'auto_resume' => true,
            ],
        ]);
    }

    public function needsCycleRefresh(CrawlCampaign $campaign, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return ($campaign->settings_json['cycle_key'] ?? null) !== $now->format('Y-m');
    }

    /** @param Collection<int, Court> $courts */
    public function prepareCycle(CrawlCampaign $campaign, Collection $courts, ?CarbonImmutable $now = null): void
    {
        $this->assertRegularCampaign($campaign);

        if ($courts->isEmpty()) {
            throw new InvalidArgumentException('At least one enabled court is required for a regular crawl cycle.');
        }

        $now ??= CarbonImmutable::now();
        $cycleKey = $now->format('Y-m');
        $targetMonth = $now->subMonthNoOverflow()->startOfMonth();
        $selectedCourtIds = $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $courts = Court::query()
            ->whereIn('id', $selectedCourtIds)
            ->with('crawlState')
            ->withCount([
                'crawlWorkItems as pending_priority_work_items_count' => fn (Builder $query) => $query
                    ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Failed])
                    ->whereIn('work_type', [CrawlWorkType::BacklogDrain, CrawlWorkType::CaseCard, CrawlWorkType::Recheck]),
            ])
            ->orderBy('crawl_priority')
            ->orderBy('id')
            ->get();
        $this->ensureCourtStates($courts);
        $courts->load('crawlState');

        DB::transaction(function () use ($campaign, $courts, $cycleKey, $targetMonth, $now): void {
            $lockedCampaign = CrawlCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $settings = $lockedCampaign->settings_json ?? [];
            $courtIds = $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

            if (($settings['cycle_key'] ?? null) !== $cycleKey) {
                $backlogCourtIds = CrawlWorkItem::query()
                    ->whereBelongsTo($lockedCampaign, 'campaign')
                    ->whereIn('work_type', [CrawlWorkType::HeadSync, CrawlWorkType::CaseCard])
                    ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
                    ->pluck('court_id')
                    ->unique()
                    ->values();

                CrawlWorkItem::query()
                    ->whereBelongsTo($lockedCampaign, 'campaign')
                    ->where('work_type', CrawlWorkType::HeadSync)
                    ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
                    ->update([
                        'work_type' => CrawlWorkType::BacklogDrain,
                        'status' => CrawlWorkStatus::Pending,
                        'priority' => 80,
                        'updated_at' => now(),
                    ]);

                CrawlWorkItem::query()
                    ->whereBelongsTo($lockedCampaign, 'campaign')
                    ->where('work_type', CrawlWorkType::CaseCard)
                    ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
                    ->update([
                        'status' => CrawlWorkStatus::Pending,
                        'priority' => 90,
                        'updated_at' => now(),
                    ]);

                if ($backlogCourtIds->isNotEmpty()) {
                    CourtCrawlState::query()->whereIn('court_id', $backlogCourtIds)->update([
                        'has_backlog' => true,
                        'updated_at' => now(),
                    ]);
                }

                $globalBudget = $this->budgetAllocator->cycleBudget($now->startOfMonth());
                $allocation = $this->budgetAllocator->allocateCourts($courts, $globalBudget);
                $settings = [
                    ...$settings,
                    'court_ids' => $courtIds,
                    'cycle_key' => $cycleKey,
                    'target_month' => $targetMonth->toDateString(),
                    'cycle_started_at' => $now->toIso8601String(),
                    'court_budgets' => $allocation['budgets'],
                    'court_hard_caps' => $allocation['hard_caps'],
                    'court_weights' => $allocation['weights'],
                    'court_usage' => array_fill_keys($courtIds, 0),
                    'lane_cursor' => 0,
                    'auto_resume' => true,
                    'rechecks_seeded_for' => null,
                ];

                $lockedCampaign->forceFill([
                    'window_from' => $targetMonth->toDateString(),
                    'window_to' => $targetMonth->endOfMonth()->toDateString(),
                    'settings_json' => $settings,
                    'request_budget' => $globalBudget,
                    'requests_used' => 0,
                    'paused_at' => null,
                    'finished_at' => null,
                    'last_heartbeat_at' => now(),
                ])->save();
            } elseif (($settings['court_ids'] ?? []) !== $courtIds) {
                $allocation = $this->budgetAllocator->allocateCourts($courts, max(1, (int) $lockedCampaign->request_budget));
                $existingUsage = $settings['court_usage'] ?? [];
                $settings['court_ids'] = $courtIds;
                $settings['court_budgets'] = $allocation['budgets'];
                $settings['court_hard_caps'] = $allocation['hard_caps'];
                $settings['court_weights'] = $allocation['weights'];
                $settings['court_usage'] = collect($courtIds)
                    ->mapWithKeys(fn (int $courtId): array => [$courtId => (int) ($existingUsage[(string) $courtId] ?? $existingUsage[$courtId] ?? 0)])
                    ->all();
                $settings['rechecks_seeded_for'] = null;
                $lockedCampaign->forceFill(['settings_json' => $settings])->save();
            }

            $campaign->setRawAttributes($lockedCampaign->getAttributes(), true);
        }, 5);

        $this->seedHeadWork($campaign->refresh(), $courts, $targetMonth, $cycleKey);
        $this->seedRechecks($campaign->refresh(), $courts, $cycleKey, $now);
    }

    public function hasRunnableWork(CrawlCampaign $campaign): bool
    {
        $campaign->refresh();

        return $this->candidate($campaign) instanceof CrawlWorkItem;
    }

    public function run(
        CrawlCampaign $campaign,
        ParserRun $run,
        int $timeLimitSeconds = 0,
        int $maximumRequests = 0,
        ?Closure $progress = null,
    ): RegularCrawlResult {
        $this->assertRegularCampaign($campaign);
        $this->resetInterruptedWork($campaign);
        $startedAt = microtime(true);
        $startingRequests = (int) $run->refresh()->total_requests;
        $steps = 0;

        while (true) {
            $requests = (int) $run->refresh()->total_requests - $startingRequests;

            if ($maximumRequests > 0 && $requests >= $maximumRequests) {
                return new RegularCrawlResult(RegularCrawlStopReason::RequestLimit, $steps, $requests);
            }

            if ($timeLimitSeconds > 0 && microtime(true) - $startedAt >= $timeLimitSeconds) {
                return new RegularCrawlResult(RegularCrawlStopReason::TimeLimit, $steps, $requests);
            }

            $campaign->refresh();
            if ($campaign->status !== CrawlCampaignStatus::Running) {
                return new RegularCrawlResult(RegularCrawlStopReason::Paused, $steps, $requests);
            }

            $workItem = $this->nextWorkItem($campaign);
            if (! $workItem instanceof CrawlWorkItem) {
                return new RegularCrawlResult(RegularCrawlStopReason::Idle, $steps, $requests);
            }

            $requestsBefore = (int) $run->refresh()->total_requests;

            try {
                $this->processWorkItem($campaign, $workItem, $run);
            } finally {
                $requestsUsed = max(0, (int) $run->refresh()->total_requests - $requestsBefore);
                $this->recordRequestUsage($campaign, $workItem, $requestsUsed);
            }

            $steps++;
            $progress?->__invoke(sprintf(
                'work=%s court=%d target=%s status=%s requests=%d/%d',
                $workItem->work_type->value,
                $workItem->court_id,
                $workItem->target_date?->toDateString() ?? '-',
                $workItem->refresh()->status->value,
                $campaign->refresh()->requests_used,
                $campaign->request_budget ?? 0,
            ));
        }
    }

    private function processWorkItem(CrawlCampaign $campaign, CrawlWorkItem $workItem, ParserRun $run): void
    {
        match ($workItem->work_type) {
            CrawlWorkType::HeadSync, CrawlWorkType::BacklogDrain => $this->processCalendarRange($campaign, $workItem, $run),
            CrawlWorkType::CaseCard, CrawlWorkType::Recheck => $this->processCaseCard($campaign, $workItem, $run),
            default => $workItem->forceFill([
                'status' => CrawlWorkStatus::Cancelled,
                'finished_at' => now(),
                'last_error' => 'Unsupported regular crawl work type.',
            ])->save(),
        };
    }

    private function processCalendarRange(CrawlCampaign $campaign, CrawlWorkItem $workItem, ParserRun $run): void
    {
        $court = Court::query()->findOrFail($workItem->court_id);
        $payload = $workItem->payload_json ?? [];
        $periodFrom = CarbonImmutable::parse((string) $payload['period_from'])->startOfDay();
        $cursorDate = CarbonImmutable::parse((string) ($payload['cursor_date'] ?? $payload['period_to']))->startOfDay();
        $this->markRunning($workItem);

        try {
            $links = $this->crawler->crawlCalendarDate($court, $cursorDate, $run);
            $caseCount = $this->enqueueCaseCards($campaign, $court, $workItem, $links);
            $nextCursorDate = $cursorDate->subDay();
            $isCompleted = $nextCursorDate->lt($periodFrom);
            $payload['cursor_date'] = $nextCursorDate->toDateString();
            $workItem->forceFill([
                'payload_json' => $payload,
                'status' => $isCompleted ? CrawlWorkStatus::Completed : CrawlWorkStatus::Pending,
                'available_at' => now(),
                'finished_at' => $isCompleted ? now() : null,
                'last_error' => null,
            ])->save();
            $this->rememberCalendarProgress($court, $workItem, $nextCursorDate, count($links), $caseCount, $isCompleted);
        } catch (SourceCircuitOpenException $exception) {
            $this->markFailed($workItem, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($workItem, $exception);
            $this->rememberCalendarFailure($court, $workItem, $cursorDate);
            $this->sourceGuard->ensureCircuitAllowsRequests();
        }
    }

    /** @param array<int, CalendarCaseLink> $links */
    private function enqueueCaseCards(CrawlCampaign $campaign, Court $court, CrawlWorkItem $calendarWork, array $links): int
    {
        $created = 0;
        $cycleKey = (string) (($calendarWork->payload_json ?? [])['cycle_key'] ?? $campaign->settings_json['cycle_key'] ?? 'unknown');

        foreach ($links as $link) {
            if (! $this->adapter->isCivilFirstInstance($link)) {
                continue;
            }

            $normalizedNumber = $this->caseNumberNormalizer->firstInstanceNumber($link->caseNumber)
                ?? $this->caseNumberNormalizer->normalize($link->caseNumber)
                ?? hash('sha256', $link->url);
            $caseWork = CrawlWorkItem::query()->firstOrCreate(
                [
                    'crawl_campaign_id' => $campaign->id,
                    'deduplication_key' => hash('sha256', 'regular-case:'.$cycleKey.':'.$court->id.':'.$normalizedNumber),
                ],
                [
                    'court_id' => $court->id,
                    'work_type' => CrawlWorkType::CaseCard,
                    'status' => CrawlWorkStatus::Pending,
                    'target_date' => $link->scheduledDate->toDateString(),
                    'priority' => 100,
                    'available_at' => now(),
                    'payload_json' => [
                        'cycle_key' => $cycleKey,
                        'case_number' => $link->caseNumber,
                        'normalized_case_number' => $normalizedNumber,
                        'url' => $link->url,
                        'case_uid' => $link->caseUid,
                        'external_case_id' => $link->externalCaseId,
                        'window_from' => ($calendarWork->payload_json ?? [])['period_from'] ?? null,
                        'window_to' => ($calendarWork->payload_json ?? [])['period_to'] ?? null,
                    ],
                ],
            );

            if ($caseWork->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function processCaseCard(CrawlCampaign $campaign, CrawlWorkItem $workItem, ParserRun $run): void
    {
        $court = Court::query()->findOrFail($workItem->court_id);
        $payload = $workItem->payload_json ?? [];
        $url = (string) ($payload['url'] ?? '');

        if ($url === '') {
            $workItem->forceFill([
                'status' => CrawlWorkStatus::Cancelled,
                'finished_at' => now(),
                'last_error' => 'Case card URL is missing.',
            ])->save();

            return;
        }

        $this->markRunning($workItem);

        try {
            $parsed = $this->crawler->crawlCase(
                $court,
                $url,
                $run,
                isset($payload['window_from']) ? CarbonImmutable::parse((string) $payload['window_from']) : null,
                isset($payload['window_to']) ? CarbonImmutable::parse((string) $payload['window_to']) : null,
                persistOutOfWindow: true,
            );

            if (! $parsed) {
                $attemptsExhausted = $workItem->attempts >= $this->maximumCaseAttempts();
                $workItem->forceFill([
                    'status' => $attemptsExhausted ? CrawlWorkStatus::Cancelled : CrawlWorkStatus::Failed,
                    'available_at' => now()->addMinutes($this->failureRetryMinutes()),
                    'finished_at' => $attemptsExhausted ? now() : null,
                    'last_error' => 'The case card could not be fetched or parsed.',
                ])->save();
                if ($attemptsExhausted) {
                    $this->refreshCourtBacklogFlag($court, $workItem);
                }
                $this->sourceGuard->ensureCircuitAllowsRequests();

                return;
            }

            $instance = CaseInstance::query()
                ->whereBelongsTo($court)
                ->where('source_url_hash', hash('sha256', $url))
                ->first();
            $workItem->forceFill([
                'case_instance_id' => $instance?->id ?? $workItem->case_instance_id,
                'status' => CrawlWorkStatus::Completed,
                'finished_at' => now(),
                'last_error' => null,
            ])->save();
            $this->rememberCaseSuccess($court, $workItem);
        } catch (SourceCircuitOpenException $exception) {
            $this->markTransientFailure($workItem, $exception);
            $this->refreshCourtBacklogFlag($court, $workItem);

            throw $exception;
        } catch (ConnectionException $exception) {
            $this->markTransientFailure($workItem, $exception);
            $this->refreshCourtBacklogFlag($court, $workItem);
            $this->sourceGuard->ensureCircuitAllowsRequests();
        } catch (Throwable $exception) {
            $this->markFailed($workItem, $exception);
            $this->refreshCourtBacklogFlag($court, $workItem);
            $this->sourceGuard->ensureCircuitAllowsRequests();
        }
    }

    private function nextWorkItem(CrawlCampaign $campaign): ?CrawlWorkItem
    {
        $settings = $campaign->settings_json ?? [];
        $laneTypes = $this->laneTypes();
        $laneCursor = (int) ($settings['lane_cursor'] ?? 0);
        $preferredType = $laneTypes[$laneCursor % count($laneTypes)];
        $overdueRecheck = $this->candidate($campaign, CrawlWorkType::Recheck, true);
        $candidate = $overdueRecheck
            ?? $this->candidate($campaign, $preferredType)
            ?? $this->candidate($campaign);

        if ($candidate instanceof CrawlWorkItem) {
            $settings['lane_cursor'] = $laneCursor + 1;
            $campaign->forceFill([
                'settings_json' => $settings,
                'last_heartbeat_at' => now(),
            ])->save();
        }

        return $candidate;
    }

    private function candidate(CrawlCampaign $campaign, ?CrawlWorkType $workType = null, bool $overdueOnly = false): ?CrawlWorkItem
    {
        $settings = $campaign->settings_json ?? [];
        $courtIds = collect($settings['court_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->all();
        $usage = collect($settings['court_usage'] ?? [])->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => (int) $value]);
        $budgets = collect($settings['court_budgets'] ?? [])->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => (int) $value]);
        $hardCaps = collect($settings['court_hard_caps'] ?? [])->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => (int) $value]);
        $withinAllocation = collect($courtIds)->filter(fn (int $courtId): bool => $usage->get($courtId, 0) < $budgets->get($courtId, 0))->values()->all();
        $candidate = $this->balancedCandidate($campaign, $withinAllocation, $usage, $budgets, $workType, $overdueOnly);

        if ($candidate instanceof CrawlWorkItem) {
            return $candidate;
        }

        $withinHardCap = collect($courtIds)->filter(fn (int $courtId): bool => $usage->get($courtId, 0) < $hardCaps->get($courtId, 0))->values()->all();
        $candidate = $this->balancedCandidate($campaign, $withinHardCap, $usage, $hardCaps, $workType, $overdueOnly);

        if ($candidate instanceof CrawlWorkItem) {
            return $candidate;
        }

        return $this->balancedCandidate($campaign, $courtIds, $usage, $hardCaps, $workType, $overdueOnly);
    }

    /**
     * @param  array<int, int>  $courtIds
     * @param  Collection<int, int>  $usage
     * @param  Collection<int, int>  $allocation
     */
    private function balancedCandidate(
        CrawlCampaign $campaign,
        array $courtIds,
        Collection $usage,
        Collection $allocation,
        ?CrawlWorkType $workType,
        bool $overdueOnly,
    ): ?CrawlWorkItem {
        $eligibleCourtIds = $this->candidateQuery($campaign, $courtIds, $workType, $overdueOnly)
            ->reorder()
            ->select('court_id')
            ->distinct()
            ->pluck('court_id')
            ->map(fn (mixed $courtId): int => (int) $courtId);

        if ($eligibleCourtIds->isEmpty()) {
            return null;
        }

        $courtOrder = array_flip($courtIds);
        $selectedCourtId = $eligibleCourtIds
            ->sort(function (int $leftCourtId, int $rightCourtId) use ($usage, $allocation, $courtOrder): int {
                $leftUtilization = $usage->get($leftCourtId, 0) / max(1, $allocation->get($leftCourtId, 1));
                $rightUtilization = $usage->get($rightCourtId, 0) / max(1, $allocation->get($rightCourtId, 1));

                return ($leftUtilization <=> $rightUtilization)
                    ?: (($courtOrder[$leftCourtId] ?? PHP_INT_MAX) <=> ($courtOrder[$rightCourtId] ?? PHP_INT_MAX));
            })
            ->first();

        return is_int($selectedCourtId)
            ? $this->candidateQuery($campaign, [$selectedCourtId], $workType, $overdueOnly)->first()
            : null;
    }

    /** @param array<int, int> $courtIds */
    private function candidateQuery(CrawlCampaign $campaign, array $courtIds, ?CrawlWorkType $workType, bool $overdueOnly): Builder
    {
        $maximumAttempts = $this->maximumCaseAttempts();
        $query = CrawlWorkItem::query()
            ->whereBelongsTo($campaign, 'campaign')
            ->whereIn('court_id', $courtIds === [] ? [-1] : $courtIds)
            ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Failed])
            ->where(function (Builder $query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->where(function (Builder $query) use ($maximumAttempts): void {
                $query->whereNotIn('work_type', [CrawlWorkType::CaseCard, CrawlWorkType::Recheck])
                    ->orWhere(function (Builder $query) use ($maximumAttempts): void {
                        $query->whereIn('work_type', [CrawlWorkType::CaseCard, CrawlWorkType::Recheck])
                            ->where('attempts', '<', $maximumAttempts);
                    });
            });

        if ($workType instanceof CrawlWorkType) {
            $query->where('work_type', $workType);
        } else {
            $query->whereIn('work_type', [CrawlWorkType::BacklogDrain, CrawlWorkType::HeadSync, CrawlWorkType::CaseCard, CrawlWorkType::Recheck]);
        }

        if ($overdueOnly) {
            $query->where('target_date', '<=', now()->subDays(max(1, $this->settings->current()->regular_recheck_starvation_days))->toDateString());
        }

        return $query
            ->orderBy('priority')
            ->orderBy('available_at')
            ->orderBy('target_date')
            ->orderBy('id');
    }

    /** @return array<int, CrawlWorkType> */
    private function laneTypes(): array
    {
        $types = collect(config('parser.regular.lane_sequence', []))
            ->map(fn (mixed $type): ?CrawlWorkType => is_string($type) ? CrawlWorkType::tryFrom($type) : null)
            ->filter()
            ->values()
            ->all();

        return $types !== [] ? $types : [
            CrawlWorkType::BacklogDrain,
            CrawlWorkType::HeadSync,
            CrawlWorkType::CaseCard,
            CrawlWorkType::HeadSync,
            CrawlWorkType::Recheck,
        ];
    }

    /** @param Collection<int, Court> $courts */
    private function seedHeadWork(CrawlCampaign $campaign, Collection $courts, CarbonImmutable $targetMonth, string $cycleKey): void
    {
        $timestamp = now();
        $rows = $courts->values()->map(fn (Court $court): array => [
            'crawl_campaign_id' => $campaign->id,
            'court_id' => $court->id,
            'work_type' => CrawlWorkType::HeadSync->value,
            'status' => CrawlWorkStatus::Pending->value,
            'deduplication_key' => hash('sha256', 'regular-head:'.$cycleKey.':'.$court->id),
            'target_date' => $targetMonth->toDateString(),
            'priority' => 100,
            'available_at' => $timestamp,
            'attempts' => 0,
            'request_cost' => 0,
            'payload_json' => json_encode([
                'cycle_key' => $cycleKey,
                'period_from' => $targetMonth->toDateString(),
                'period_to' => $targetMonth->endOfMonth()->toDateString(),
                'cursor_date' => $targetMonth->endOfMonth()->toDateString(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $rows->chunk(500)->each(fn (Collection $chunk) => CrawlWorkItem::query()->insertOrIgnore($chunk->all()));
    }

    /** @param Collection<int, Court> $courts */
    private function seedRechecks(CrawlCampaign $campaign, Collection $courts, string $cycleKey, CarbonImmutable $now): void
    {
        $campaign->refresh();
        if (($campaign->settings_json['rechecks_seeded_for'] ?? null) === $cycleKey) {
            return;
        }

        $courtIds = $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $cutoff = $now->subDays(max(1, $this->settings->current()->regular_recheck_interval_days));

        CaseInstance::query()
            ->whereIn('court_id', $courtIds)
            ->where('court_instance_status_normalized', 'active')
            ->where('updated_at', '<=', $cutoff)
            ->select(['id', 'case_id', 'court_id', 'source_url', 'updated_at'])
            ->with(['courtCase:id,observation_window_from,observation_window_to'])
            ->chunkById(500, function (Collection $instances) use ($campaign, $cycleKey): void {
                $timestamp = now();
                $rows = $instances->map(fn (CaseInstance $instance): array => [
                    'crawl_campaign_id' => $campaign->id,
                    'court_id' => $instance->court_id,
                    'case_instance_id' => $instance->id,
                    'work_type' => CrawlWorkType::Recheck->value,
                    'status' => CrawlWorkStatus::Pending->value,
                    'deduplication_key' => hash('sha256', 'regular-recheck:'.$cycleKey.':'.$instance->id),
                    'target_date' => $instance->updated_at?->toDateString(),
                    'priority' => 120,
                    'available_at' => $timestamp,
                    'attempts' => 0,
                    'request_cost' => 0,
                    'payload_json' => json_encode([
                        'cycle_key' => $cycleKey,
                        'url' => $instance->source_url,
                        'window_from' => $instance->courtCase?->observation_window_from?->toDateString(),
                        'window_to' => $instance->courtCase?->observation_window_to?->toDateString(),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                CrawlWorkItem::query()->insertOrIgnore($rows->all());
            });

        $settings = $campaign->refresh()->settings_json ?? [];
        $settings['rechecks_seeded_for'] = $cycleKey;
        $campaign->forceFill(['settings_json' => $settings])->save();
    }

    /** @param Collection<int, Court> $courts */
    private function ensureCourtStates(Collection $courts): void
    {
        $timestamp = now();
        $rows = $courts->map(fn (Court $court): array => [
            'court_id' => $court->id,
            'has_backlog' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $rows->chunk(500)->each(fn (Collection $chunk) => CourtCrawlState::query()->insertOrIgnore($chunk->all()));
    }

    private function markRunning(CrawlWorkItem $workItem): void
    {
        $workItem->forceFill([
            'status' => CrawlWorkStatus::Running,
            'started_at' => $workItem->started_at ?? now(),
            'finished_at' => null,
            'attempts' => $workItem->attempts + 1,
            'last_error' => null,
        ])->save();
    }

    private function markTransientFailure(CrawlWorkItem $workItem, Throwable $exception): void
    {
        $workItem->forceFill([
            'status' => CrawlWorkStatus::Pending,
            'attempts' => max(0, $workItem->attempts - 1),
            'available_at' => now()->addMinutes($this->failureRetryMinutes()),
            'finished_at' => null,
            'last_error' => $exception->getMessage(),
        ])->save();
    }

    private function markFailed(CrawlWorkItem $workItem, Throwable $exception): void
    {
        $isCaseWork = in_array($workItem->work_type, [CrawlWorkType::CaseCard, CrawlWorkType::Recheck], true);
        $attemptsExhausted = $isCaseWork && $workItem->attempts >= $this->maximumCaseAttempts();
        $workItem->forceFill([
            'status' => $attemptsExhausted ? CrawlWorkStatus::Cancelled : CrawlWorkStatus::Failed,
            'available_at' => now()->addMinutes($this->failureRetryMinutes()),
            'finished_at' => $attemptsExhausted ? now() : null,
            'last_error' => $exception->getMessage(),
        ])->save();
    }

    private function recordRequestUsage(CrawlCampaign $campaign, CrawlWorkItem $workItem, int $requestsUsed): void
    {
        if ($requestsUsed <= 0) {
            $campaign->forceFill(['last_heartbeat_at' => now()])->save();

            return;
        }

        DB::transaction(function () use ($campaign, $workItem, $requestsUsed): void {
            $lockedCampaign = CrawlCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $settings = $lockedCampaign->settings_json ?? [];
            $courtUsage = $settings['court_usage'] ?? [];
            $courtKey = (string) $workItem->court_id;
            $courtUsage[$courtKey] = (int) ($courtUsage[$courtKey] ?? 0) + $requestsUsed;
            $settings['court_usage'] = $courtUsage;
            $lockedCampaign->forceFill([
                'settings_json' => $settings,
                'requests_used' => $lockedCampaign->requests_used + $requestsUsed,
                'last_heartbeat_at' => now(),
            ])->save();
            $workItem->increment('request_cost', $requestsUsed);
            $campaign->setRawAttributes($lockedCampaign->getAttributes(), true);
        }, 5);
    }

    private function rememberCalendarProgress(Court $court, CrawlWorkItem $workItem, CarbonImmutable $nextCursor, int $links, int $createdCases, bool $completed): void
    {
        $state = CourtCrawlState::query()->firstOrNew(['court_id' => $court->id]);
        $statistics = $state->stats_json ?? [];
        $statistics['regular_calendar_days'] = (int) ($statistics['regular_calendar_days'] ?? 0) + 1;
        $statistics['regular_case_links'] = (int) ($statistics['regular_case_links'] ?? 0) + $links;
        $statistics['regular_discovered_cases'] = (int) ($statistics['regular_discovered_cases'] ?? 0) + $createdCases;
        $isBacklog = $workItem->work_type === CrawlWorkType::BacklogDrain;
        $state->forceFill([
            $isBacklog ? 'backlog_cursor_date' : 'head_cursor_date' => $nextCursor->toDateString(),
            'has_backlog' => $isBacklog ? ! $completed || $this->courtHasBacklog($workItem) : $state->has_backlog,
            'last_attempted_at' => now(),
            'last_successful_at' => now(),
            'next_eligible_at' => now(),
            'stats_json' => $statistics,
        ])->save();

        if ($completed) {
            $court->forceFill([
                'last_checked_at' => now(),
                'last_successful_crawl_at' => now(),
                'status' => 'active',
            ])->save();
        }
    }

    private function rememberCalendarFailure(Court $court, CrawlWorkItem $workItem, CarbonImmutable $cursorDate): void
    {
        $state = CourtCrawlState::query()->firstOrNew(['court_id' => $court->id]);
        $isBacklog = $workItem->work_type === CrawlWorkType::BacklogDrain;
        $state->forceFill([
            $isBacklog ? 'backlog_cursor_date' : 'head_cursor_date' => $cursorDate->toDateString(),
            'has_backlog' => $isBacklog || $state->has_backlog,
            'last_attempted_at' => now(),
            'next_eligible_at' => now()->addMinutes($this->failureRetryMinutes()),
        ])->save();
    }

    private function rememberCaseSuccess(Court $court, CrawlWorkItem $workItem): void
    {
        $state = CourtCrawlState::query()->firstOrNew(['court_id' => $court->id]);
        $statistics = $state->stats_json ?? [];
        $key = $workItem->work_type === CrawlWorkType::Recheck ? 'regular_rechecks' : 'regular_case_cards';
        $statistics[$key] = (int) ($statistics[$key] ?? 0) + 1;
        $hasBacklog = $workItem->work_type === CrawlWorkType::CaseCard && $state->has_backlog
            ? $this->courtHasBacklog($workItem)
            : $state->has_backlog;
        $state->forceFill([
            'has_backlog' => $hasBacklog,
            'last_attempted_at' => now(),
            'last_successful_at' => now(),
            'next_eligible_at' => now(),
            'stats_json' => $statistics,
        ])->save();
    }

    private function refreshCourtBacklogFlag(Court $court, CrawlWorkItem $workItem): void
    {
        if ($workItem->work_type !== CrawlWorkType::CaseCard) {
            return;
        }

        $state = CourtCrawlState::query()->firstOrNew(['court_id' => $court->id]);
        if ($state->has_backlog) {
            $state->forceFill(['has_backlog' => $this->courtHasBacklog($workItem)])->save();
        }
    }

    private function courtHasBacklog(CrawlWorkItem $workItem): bool
    {
        return CrawlWorkItem::query()
            ->where('crawl_campaign_id', $workItem->crawl_campaign_id)
            ->where('court_id', $workItem->court_id)
            ->whereIn('work_type', [CrawlWorkType::BacklogDrain, CrawlWorkType::CaseCard])
            ->whereIn('status', [CrawlWorkStatus::Pending, CrawlWorkStatus::Running, CrawlWorkStatus::Failed])
            ->whereKeyNot($workItem->id)
            ->exists();
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

    private function maximumCaseAttempts(): int
    {
        return max(1, $this->settings->current()->regular_maximum_case_attempts);
    }

    private function failureRetryMinutes(): int
    {
        return max(1, $this->settings->current()->regular_failure_retry_minutes);
    }

    private function assertRegularCampaign(CrawlCampaign $campaign): void
    {
        if ($campaign->mode !== CrawlCampaignMode::Regular) {
            throw new InvalidArgumentException('A regular crawl campaign is required.');
        }
    }
}
