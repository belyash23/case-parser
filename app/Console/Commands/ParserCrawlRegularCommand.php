<?php

namespace App\Console\Commands;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\ParserRun;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Services\CourtScopeResolver;
use App\Parser\Services\CrawlCampaignManager;
use App\Parser\Services\RegularCrawlService;
use App\Parser\Services\SudrfDirectorySyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ParserCrawlRegularCommand extends Command
{
    protected $signature = 'parser:crawl-regular
        {--court=* : Limit this regular campaign to enabled court IDs}
        {--region=* : Limit this regular campaign to SUDRF region IDs}
        {--resume : Resume the matching non-terminal regular campaign}
        {--campaign= : Resume a specific regular campaign ID}
        {--time-limit=0 : Stop this process slice after this many seconds; zero is unlimited}
        {--max-requests=0 : Stop this process slice after this many requests; zero is unlimited}
        {--skip-directory-sync : Do not refresh the SUDRF directory at the start of a new monthly cycle}
        {--scheduled : Treat an active initial campaign as an expected no-op}';

    protected $description = 'Continuously maintain the dataset with monthly head sync, backlog drain, and active-case rechecks.';

    public function handle(
        SudrfDirectorySyncService $directorySync,
        CourtScopeResolver $scopeResolver,
        RegularCrawlService $regularCrawl,
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
            $maximumRequests = $this->nonNegativeIntegerOption('max-requests');
            $requestedCourtIds = $this->positiveIntegerOption('court');
            $requestedRegionIds = $this->positiveIntegerOption('region');
            $this->assertResumeOptions($requestedCourtIds, $requestedRegionIds);

            if ($this->isBlockedByInitialCampaign()) {
                if ((bool) $this->option('scheduled')) {
                    $this->line('Regular crawl skipped while an initial campaign is active.');

                    return self::SUCCESS;
                }

                throw new InvalidArgumentException('An initial crawl campaign is active. Stop or finish it before running regular maintenance.');
            }

            $requestedScopeKey = $scopeResolver->scopeKey($requestedCourtIds, $requestedRegionIds);
            $campaign = $this->resolveCampaign($requestedScopeKey);
            $courtIds = $campaign instanceof CrawlCampaign
                ? $this->integerArray($campaign->settings_json['court_filter'] ?? [])
                : $requestedCourtIds;
            $regionIds = $campaign instanceof CrawlCampaign
                ? $this->integerArray($campaign->settings_json['region_filter'] ?? [])
                : $requestedRegionIds;
            $cycleNeedsRefresh = ! $campaign instanceof CrawlCampaign || $regularCrawl->needsCycleRefresh($campaign);

            if ($cycleNeedsRefresh && ! (bool) $this->option('skip-directory-sync')) {
                $syncRegionIds = $regionIds !== [] ? $regionIds : $scopeResolver->regionIdsForCourts($courtIds);
                $result = $directorySync->sync($syncRegionIds);
                $this->line(sprintf(
                    'Directory sync: %d regions, %d courts, %d failures.',
                    $result['regions'],
                    $result['courts'],
                    $result['failures'],
                ));
            }

            $courts = $scopeResolver->resolve($courtIds, $regionIds);
            $campaign ??= $regularCrawl->createCampaign($courts, [
                'scope_key' => $requestedScopeKey,
                'court_filter' => $courtIds,
                'region_filter' => $regionIds,
            ]);
            $campaignManager->start($campaign);
            $campaignStarted = true;
            $regularCrawl->prepareCycle($campaign->refresh(), $courts);

            if (! $regularCrawl->hasRunnableWork($campaign->refresh())) {
                $campaignManager->pause($campaign->refresh());
                $campaignStarted = false;
                $this->info(sprintf(
                    'Regular campaign %d has no currently runnable work in its %s cycle.',
                    $campaign->id,
                    $campaign->settings_json['cycle_key'] ?? 'current',
                ));

                return self::SUCCESS;
            }

            $this->markInterruptedRuns($campaign);
            $run = $this->createRun($campaign);
            $progress = $this->output->isVerbose() ? fn (string $message) => $this->line($message) : null;
            $result = $regularCrawl->run($campaign, $run, $timeLimit, $maximumRequests, $progress);
            $run->refresh()->markCompleted();
            $campaignManager->pause($campaign->refresh());
            $campaignStarted = false;

            $this->info(sprintf(
                'Regular campaign %d paused (%s): %d steps, %d requests in this slice, %d requests used in cycle (allocation plan: %d).',
                $campaign->id,
                $result->reason->value,
                $result->steps,
                $result->requests,
                $campaign->refresh()->requests_used,
                $campaign->request_budget ?? 0,
            ));

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

    private function resolveCampaign(string $scopeKey): ?CrawlCampaign
    {
        $campaignId = $this->option('campaign');

        if (is_string($campaignId) && $campaignId !== '') {
            if (! ctype_digit($campaignId) || (int) $campaignId < 1) {
                throw new InvalidArgumentException('--campaign must be a positive campaign ID.');
            }

            $campaign = CrawlCampaign::query()->find((int) $campaignId);

            return $this->assertResumableCampaign($campaign);
        }

        if ((bool) $this->option('resume')) {
            $activeCampaign = SourceRuntimeState::query()
                ->where('source_type', 'sudrf')
                ->with('activeCampaign')
                ->first()
                ?->activeCampaign;

            if ($activeCampaign instanceof CrawlCampaign && $activeCampaign->mode === CrawlCampaignMode::Regular && ! $activeCampaign->status->isTerminal()) {
                return $activeCampaign;
            }
        }

        foreach (CrawlCampaign::query()
            ->where('mode', CrawlCampaignMode::Regular)
            ->whereNotIn('status', [CrawlCampaignStatus::Completed, CrawlCampaignStatus::Failed, CrawlCampaignStatus::Cancelled])
            ->latest('id')
            ->cursor() as $campaign) {
            if ((bool) $this->option('scheduled') && ($campaign->settings_json['auto_resume'] ?? true) === false) {
                continue;
            }

            if (($campaign->settings_json['scope_key'] ?? null) === $scopeKey) {
                return $campaign;
            }
        }

        if ((bool) $this->option('resume')) {
            throw new InvalidArgumentException('No resumable regular campaign was found.');
        }

        return null;
    }

    private function assertResumableCampaign(?CrawlCampaign $campaign): CrawlCampaign
    {
        if (! $campaign instanceof CrawlCampaign || $campaign->mode !== CrawlCampaignMode::Regular || $campaign->status->isTerminal()) {
            throw new InvalidArgumentException('No resumable regular campaign was found.');
        }

        return $campaign;
    }

    private function isBlockedByInitialCampaign(): bool
    {
        $activeCampaign = SourceRuntimeState::query()
            ->where('source_type', 'sudrf')
            ->with('activeCampaign')
            ->first()
            ?->activeCampaign;

        return $activeCampaign instanceof CrawlCampaign
            && $activeCampaign->mode === CrawlCampaignMode::Initial
            && ! $activeCampaign->status->isTerminal();
    }

    /**
     * @param  array<int, int>  $courtIds
     * @param  array<int, int>  $regionIds
     */
    private function assertResumeOptions(array $courtIds, array $regionIds): void
    {
        if (((bool) $this->option('resume') || $this->option('campaign') !== null) && ($courtIds !== [] || $regionIds !== [])) {
            throw new InvalidArgumentException('--court and --region cannot be combined with --resume or --campaign.');
        }
    }

    /** @return array<int, int> */
    private function positiveIntegerOption(string $name): array
    {
        $values = $this->option($name);
        $invalid = collect($values)->contains(fn (mixed $value): bool => ! ctype_digit((string) $value) || (int) $value < 1);

        if ($invalid) {
            throw new InvalidArgumentException("Every --{$name} value must be a positive integer.");
        }

        return collect($values)->map(fn (mixed $value): int => (int) $value)->unique()->values()->all();
    }

    private function nonNegativeIntegerOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new InvalidArgumentException("--{$name} must be a non-negative integer.");
        }

        return (int) $value;
    }

    /** @return array<int, int> */
    private function integerArray(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
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
            'run_type' => 'regular_campaign_slice',
            'status' => 'running',
            'started_at' => now(),
            'parser_version' => config('parser.version'),
            'settings_json' => [
                'campaign_id' => $campaign->id,
                'cycle_key' => $campaign->settings_json['cycle_key'] ?? null,
                'target_month' => $campaign->settings_json['target_month'] ?? null,
            ],
        ]);
    }
}
