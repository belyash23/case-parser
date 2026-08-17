<?php

namespace App\Console\Commands;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use App\Models\Parser\Court;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\ParserRun;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Services\CrawlCampaignManager;
use App\Parser\Services\InitialCrawlService;
use App\Parser\Services\SudrfDirectorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class ParserCrawlInitialCommand extends Command
{
    protected $signature = 'parser:crawl-initial
        {--from= : Start date YYYY-MM-DD for a new campaign}
        {--to= : End date YYYY-MM-DD for a new campaign}
        {--court=* : Limit a new campaign to enabled court IDs}
        {--region=* : Limit a new campaign to SUDRF region IDs}
        {--resume : Resume the active initial campaign}
        {--campaign= : Resume a specific initial campaign ID}
        {--time-limit=0 : Pause this process slice after this many seconds; zero is unlimited}
        {--scheduled : Run as a scheduler-managed slice}
        {--skip-directory-sync : Do not refresh the SUDRF directory before a new campaign}';

    protected $description = 'Build the initial dataset by crawling months, courts, and dates in reverse chronological order.';

    public function handle(
        SudrfDirectorySyncService $directorySync,
        InitialCrawlService $initialCrawl,
        CrawlCampaignManager $campaignManager,
        ParserSettings $settings,
    ): int {
        $campaign = null;
        $run = null;
        $campaignStarted = false;

        try {
            $timeLimit = $this->nonNegativeIntegerOption('time-limit');

            if ((bool) $this->option('scheduled') && $timeLimit === 0) {
                $timeLimit = max(10, $settings->current()->regular_slice_seconds);
            }

            $campaign = $this->resolveCampaign($directorySync, $initialCrawl);
            $campaignManager->start($campaign);
            $campaignStarted = true;
            $this->markInterruptedRuns($campaign);
            $run = $this->createRun($campaign);

            $this->info(sprintf(
                'Initial campaign %d: %s through %s, %d courts.',
                $campaign->id,
                $campaign->window_to?->toDateString(),
                $campaign->window_from?->toDateString(),
                count($campaign->settings_json['court_ids'] ?? []),
            ));

            $completed = $initialCrawl->run(
                $campaign,
                $run,
                fn (string $message) => $this->line($message),
                $timeLimit,
            );

            if (! $completed) {
                $run->refresh()->markPaused();
                $campaignManager->pause($campaign->refresh());
                $campaignStarted = false;
                $this->info(sprintf('Initial campaign %d paused with its cursor saved.', $campaign->id));

                return self::SUCCESS;
            }

            $run->refresh()->markCompleted();
            $campaignManager->finish($campaign->refresh(), CrawlCampaignStatus::Completed);
            $campaignStarted = false;
            $failedCaseCards = $campaign->workItems()
                ->where('work_type', CrawlWorkType::CaseCard)
                ->where('status', CrawlWorkStatus::Failed)
                ->count();

            $this->info(sprintf(
                'Campaign %d completed: %d requests, %d links, %d new cases, %d updated cases.',
                $campaign->id,
                $run->refresh()->total_requests,
                $run->calendar_case_links_count,
                $run->new_cases_count,
                $run->updated_cases_count,
            ));

            if ($failedCaseCards > 0) {
                $this->warn("{$failedCaseCards} case cards remain failed after their retry limit.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($run instanceof ParserRun && $run->refresh()->status === 'running') {
                $run->markPaused();
            }

            if ($campaignStarted && $campaign instanceof CrawlCampaign) {
                $campaignManager->pause($campaign->refresh());
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveCampaign(SudrfDirectorySyncService $directorySync, InitialCrawlService $initialCrawl): CrawlCampaign
    {
        if ((bool) $this->option('resume') || $this->option('campaign') !== null) {
            return $this->resumableCampaign();
        }

        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $from->gt($to)) {
            throw new \InvalidArgumentException('A new campaign requires valid --from and --to dates with --from <= --to.');
        }

        $courtIds = $this->positiveIntegerOption('court');
        $regionIds = $this->positiveIntegerOption('region');

        if (! (bool) $this->option('skip-directory-sync')) {
            $syncRegionIds = $regionIds !== [] ? $regionIds : $this->regionIdsForCourts($courtIds);
            $result = $directorySync->sync($syncRegionIds);
            $this->line(sprintf(
                'Directory sync: %d regions, %d courts, %d failures.',
                $result['regions'],
                $result['courts'],
                $result['failures'],
            ));
        }

        $courts = $this->selectedCourts($courtIds, $regionIds);

        return $initialCrawl->createCampaign($from, $to, $courts, [
            'court_filter' => $courtIds,
            'region_filter' => $regionIds,
            'directory_synced' => ! (bool) $this->option('skip-directory-sync'),
        ]);
    }

    private function resumableCampaign(): CrawlCampaign
    {
        $campaignId = $this->option('campaign');

        if (is_string($campaignId) && $campaignId !== '') {
            if (! ctype_digit($campaignId) || (int) $campaignId < 1) {
                throw new \InvalidArgumentException('--campaign must be a positive campaign ID.');
            }

            $campaign = CrawlCampaign::query()->find((int) $campaignId);
        } else {
            $campaign = SourceRuntimeState::query()
                ->where('source_type', 'sudrf')
                ->with('activeCampaign')
                ->first()
                ?->activeCampaign;
        }

        if (! $campaign instanceof CrawlCampaign || $campaign->mode !== CrawlCampaignMode::Initial || $campaign->status->isTerminal()) {
            throw new \InvalidArgumentException('No resumable initial campaign was found.');
        }

        return $campaign;
    }

    /** @param array<int, int> $courtIds */
    private function regionIdsForCourts(array $courtIds): array
    {
        if ($courtIds === []) {
            return [];
        }

        return Court::query()
            ->whereIn('id', $courtIds)
            ->whereNotNull('region_id')
            ->with('region:id,sudrf_region_id')
            ->get(['id', 'region_id'])
            ->pluck('region.sudrf_region_id')
            ->filter()
            ->map(fn (mixed $regionId): int => (int) $regionId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $courtIds
     * @param  array<int, int>  $regionIds
     * @return Collection<int, Court>
     */
    private function selectedCourts(array $courtIds, array $regionIds): Collection
    {
        $courts = Court::query()
            ->where('source_type', 'sudrf')
            ->where('is_enabled', true)
            ->when($courtIds !== [], fn ($query) => $query->whereIn('id', $courtIds))
            ->when($regionIds !== [], fn ($query) => $query->whereHas('region', fn ($regionQuery) => $regionQuery->whereIn('sudrf_region_id', $regionIds)))
            ->orderBy('crawl_priority')
            ->orderBy('id')
            ->get();

        if ($courts->isEmpty()) {
            throw new \InvalidArgumentException('No enabled SUDRF courts matched the requested scope.');
        }

        if ($courtIds !== []) {
            $missingCourtIds = array_values(array_diff($courtIds, $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));

            if ($missingCourtIds !== []) {
                throw new \InvalidArgumentException('Some selected courts are missing, disabled, or outside the region filter: '.implode(', ', $missingCourtIds));
            }
        }

        return $courts;
    }

    /** @return array<int, int> */
    private function positiveIntegerOption(string $name): array
    {
        $values = $this->option($name);
        $invalid = collect($values)->contains(fn (mixed $value): bool => ! ctype_digit((string) $value) || (int) $value < 1);

        if ($invalid) {
            throw new \InvalidArgumentException("Every --{$name} value must be a positive integer.");
        }

        return collect($values)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function nonNegativeIntegerOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new \InvalidArgumentException("--{$name} must be a non-negative integer.");
        }

        return (int) $value;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function markInterruptedRuns(CrawlCampaign $campaign): void
    {
        $campaign->parserRuns()
            ->where('status', 'running')
            ->update([
                'status' => 'interrupted',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createRun(CrawlCampaign $campaign): ParserRun
    {
        return ParserRun::query()->create([
            'crawl_campaign_id' => $campaign->id,
            'run_type' => 'initial_campaign_session',
            'status' => 'running',
            'started_at' => now(),
            'parser_version' => config('parser.version'),
            'settings_json' => [
                'campaign_id' => $campaign->id,
                'from' => $campaign->window_from?->toDateString(),
                'to' => $campaign->window_to?->toDateString(),
                'traversal_order' => 'month_desc,court_order,date_desc',
            ],
        ]);
    }
}
