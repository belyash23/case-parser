<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use App\Models\Parser\Court;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RegularCrawlBudgetAllocator
{
    public function __construct(private readonly ParserSettings $settings) {}

    public function cycleBudget(CarbonImmutable $cycleMonth): int
    {
        $settings = $this->settings->current();
        $intervalMilliseconds = max(1000, $settings->request_interval_ms);
        $utilizationPercent = min(100, max(1, $settings->capacity_utilization_percent));
        $availableMilliseconds = $cycleMonth->daysInMonth * 24 * 60 * 60 * 1000;

        return max(1, (int) floor(($availableMilliseconds / $intervalMilliseconds) * ($utilizationPercent / 100)));
    }

    /**
     * @param  Collection<int, Court>  $courts
     * @return array{budgets:array<int, int>, hard_caps:array<int, int>, weights:array<int, float>}
     */
    public function allocateCourts(Collection $courts, int $globalBudget): array
    {
        if ($courts->isEmpty()) {
            return ['budgets' => [], 'hard_caps' => [], 'weights' => []];
        }

        $globalBudget = max(1, $globalBudget);
        $weights = $this->courtWeights($courts);
        $courtIds = $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $courtCount = count($courtIds);
        $maximumSharePercent = min(100, max(1, $this->settings->current()->maximum_court_share_percent));
        $configuredShareCap = (int) ceil($globalBudget * ($maximumSharePercent / 100));
        $fairShare = (int) ceil($globalBudget / $courtCount);
        $hardCap = min($globalBudget, max(1, $configuredShareCap, $fairShare));
        $hardCaps = array_fill_keys($courtIds, $hardCap);
        $budgets = array_fill_keys($courtIds, 0);

        if ($globalBudget < $courtCount) {
            foreach (array_slice($courtIds, 0, $globalBudget) as $courtId) {
                $budgets[$courtId] = 1;
            }

            return [
                'budgets' => $budgets,
                'hard_caps' => $hardCaps,
                'weights' => $weights,
            ];
        }

        foreach ($courtIds as $courtId) {
            $budgets[$courtId] = 1;
        }

        $remaining = $globalBudget - $courtCount;
        $baseSharePercent = min(100, max(0, $this->settings->current()->base_budget_percent));
        $basePool = (int) floor($globalBudget * ($baseSharePercent / 100));
        $baseRemainder = min($remaining, max(0, $basePool - $courtCount));
        $remaining -= $baseRemainder - $this->distribute($budgets, $hardCaps, array_fill_keys($courtIds, 1.0), $baseRemainder);
        $this->distribute($budgets, $hardCaps, $weights, $remaining);

        return [
            'budgets' => $budgets,
            'hard_caps' => $hardCaps,
            'weights' => $weights,
        ];
    }

    /**
     * @param  Collection<int, Court>  $courts
     * @return array<int, float>
     */
    private function courtWeights(Collection $courts): array
    {
        return $courts->mapWithKeys(function (Court $court): array {
            $state = $court->crawlState;
            $statistics = $state?->stats_json ?? [];
            $calendarDays = max(1, (int) ($statistics['initial_calendar_days'] ?? 0));
            $averageCases = (int) ($statistics['initial_case_links'] ?? 0) / $calendarDays;
            $staleDays = $state?->last_successful_at?->diffInDays(now()) ?? 365;
            $stalenessBoost = 1 + min(3, max(0, $staleDays) / 30);
            $backlogBoost = $state?->has_backlog ? 2.0 : 1.0;
            $pendingWorkBoost = 1 + min(3, max(0, (int) ($court->pending_priority_work_items_count ?? 0)) / 10);

            return [$court->id => max(1.0, (1 + $averageCases) * $stalenessBoost * $backlogBoost * $pendingWorkBoost)];
        })->all();
    }

    /**
     * @param  array<int, int>  $budgets
     * @param  array<int, int>  $hardCaps
     * @param  array<int, float>  $weights
     */
    private function distribute(array &$budgets, array $hardCaps, array $weights, int $amount): int
    {
        while ($amount > 0) {
            $eligibleCourtIds = collect(array_keys($budgets))
                ->filter(fn (int $courtId): bool => $budgets[$courtId] < $hardCaps[$courtId])
                ->values()
                ->all();

            if ($eligibleCourtIds === []) {
                break;
            }

            $amountAtStart = $amount;
            $totalWeight = max(1.0, array_sum(array_intersect_key($weights, array_flip($eligibleCourtIds))));
            $distributed = 0;

            foreach ($eligibleCourtIds as $courtId) {
                if ($amount <= 0) {
                    break;
                }

                $proportionalShare = max(1, (int) floor($amountAtStart * (($weights[$courtId] ?? 1.0) / $totalWeight)));
                $grant = min($amount, $hardCaps[$courtId] - $budgets[$courtId], $proportionalShare);
                $budgets[$courtId] += $grant;
                $amount -= $grant;
                $distributed += $grant;
            }

            if ($distributed === 0) {
                break;
            }
        }

        return $amount;
    }
}
