<?php

namespace App\Parser\Services;

use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Exceptions\ActiveCrawlCampaignException;
use Illuminate\Support\Facades\DB;

class CrawlCampaignManager
{
    public function start(CrawlCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $state = $this->lockedSourceState($campaign->source_type);
            $activeCampaign = $state->activeCampaign()->first();

            if ($activeCampaign instanceof CrawlCampaign) {
                if ($activeCampaign->id !== $campaign->id && ! $activeCampaign->status->isTerminal()) {
                    if (! $this->canYieldTo($activeCampaign, $campaign)) {
                        throw new ActiveCrawlCampaignException($activeCampaign->id, $campaign->source_type);
                    }

                    $activeCampaign->forceFill([
                        'status' => CrawlCampaignStatus::Paused,
                        'paused_at' => now(),
                        'last_heartbeat_at' => now(),
                    ])->save();
                    $state->active_crawl_campaign_id = null;
                }

                if ($activeCampaign->id === $campaign->id && $this->hasLiveRunner($activeCampaign)) {
                    throw new ActiveCrawlCampaignException($activeCampaign->id, $campaign->source_type);
                }

                if ($activeCampaign->id !== $campaign->id) {
                    $state->active_crawl_campaign_id = null;
                }
            }

            $campaign->forceFill([
                'status' => CrawlCampaignStatus::Running,
                'started_at' => $campaign->started_at ?? now(),
                'paused_at' => null,
                'last_heartbeat_at' => now(),
            ])->save();

            $state->active_crawl_campaign_id = $campaign->id;
            $state->save();
        }, 5);
    }

    public function pause(CrawlCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $state = $this->lockedSourceState($campaign->source_type);
            $campaign->forceFill([
                'status' => CrawlCampaignStatus::Paused,
                'paused_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();

            if ($state->active_crawl_campaign_id === null) {
                $state->active_crawl_campaign_id = $campaign->id;
                $state->save();
            }
        }, 5);
    }

    public function finish(CrawlCampaign $campaign, CrawlCampaignStatus $status): void
    {
        if (! $status->isTerminal()) {
            throw new \InvalidArgumentException('A campaign can only be finished with a terminal status.');
        }

        DB::transaction(function () use ($campaign, $status): void {
            $state = $this->lockedSourceState($campaign->source_type);
            $campaign->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();

            if ($state->active_crawl_campaign_id === $campaign->id) {
                $state->active_crawl_campaign_id = null;
                $state->save();
            }
        }, 5);
    }

    private function canYieldTo(CrawlCampaign $activeCampaign, CrawlCampaign $incomingCampaign): bool
    {
        if ($activeCampaign->mode === CrawlCampaignMode::Initial) {
            return false;
        }

        if ($incomingCampaign->mode === CrawlCampaignMode::Initial) {
            return ! $this->hasLiveRunner($activeCampaign);
        }

        return $activeCampaign->status === CrawlCampaignStatus::Paused
            || ! $this->hasLiveRunner($activeCampaign);
    }

    private function hasLiveRunner(CrawlCampaign $campaign): bool
    {
        if ($campaign->status !== CrawlCampaignStatus::Running || $campaign->last_heartbeat_at === null) {
            return false;
        }

        $staleAfterSeconds = max(60, (int) config('parser.campaign.stale_after_seconds', 900));

        return $campaign->last_heartbeat_at->gt(now()->subSeconds($staleAfterSeconds));
    }

    private function lockedSourceState(string $sourceType): SourceRuntimeState
    {
        SourceRuntimeState::query()->firstOrCreate(['source_type' => $sourceType]);

        return SourceRuntimeState::query()
            ->where('source_type', $sourceType)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
