<?php

namespace Tests\Feature\Parser;

use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Exceptions\ActiveCrawlCampaignException;
use App\Parser\Services\CrawlCampaignManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlCampaignManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_stale_campaign_can_be_resumed_after_an_abrupt_process_exit(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 12:00:00');
        config()->set('parser.campaign.stale_after_seconds', 60);
        $manager = app(CrawlCampaignManager::class);
        $campaign = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($campaign);
        CarbonImmutable::setTestNow('2026-08-16 12:01:01');
        $manager->start($campaign->refresh());

        $this->assertSame(CrawlCampaignStatus::Running, $campaign->refresh()->status);
        $this->assertSame('2026-08-16 12:01:01', $campaign->last_heartbeat_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_live_campaign_cannot_be_started_by_a_second_process(): void
    {
        $manager = app(CrawlCampaignManager::class);
        $campaign = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($campaign);

        $this->expectException(ActiveCrawlCampaignException::class);
        $manager->start($campaign->refresh());
    }

    public function test_paused_initial_campaign_keeps_the_source_reserved_until_it_is_resumed(): void
    {
        $manager = app(CrawlCampaignManager::class);
        $initial = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);
        $regular = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($initial);
        $manager->pause($initial);

        $state = SourceRuntimeState::query()->firstOrFail();
        $this->assertSame(CrawlCampaignStatus::Paused, $initial->refresh()->status);
        $this->assertSame($initial->id, $state->active_crawl_campaign_id);

        $this->expectException(ActiveCrawlCampaignException::class);
        $manager->start($regular);
    }

    public function test_only_one_campaign_can_be_active_for_sudrf(): void
    {
        $manager = app(CrawlCampaignManager::class);
        $initial = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);
        $regular = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($initial);
        $state = SourceRuntimeState::query()->firstOrFail();

        $this->assertSame($initial->id, $state->active_crawl_campaign_id);
        $this->assertSame(CrawlCampaignStatus::Running, $initial->refresh()->status);

        try {
            $manager->start($regular);
            $this->fail('A second campaign should not start while the first one is active.');
        } catch (ActiveCrawlCampaignException $exception) {
            $this->assertSame($initial->id, $exception->campaignId);
        }

        $manager->finish($initial, CrawlCampaignStatus::Completed);
        $manager->start($regular);

        $this->assertSame($regular->id, $state->refresh()->active_crawl_campaign_id);
        $this->assertSame(CrawlCampaignStatus::Running, $regular->refresh()->status);
    }

    public function test_paused_regular_campaign_yields_to_an_initial_campaign(): void
    {
        $manager = app(CrawlCampaignManager::class);
        $regular = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'status' => CrawlCampaignStatus::Pending,
        ]);
        $initial = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($regular);
        $manager->pause($regular);
        $manager->start($initial);

        $this->assertSame(CrawlCampaignStatus::Paused, $regular->refresh()->status);
        $this->assertSame(CrawlCampaignStatus::Running, $initial->refresh()->status);
        $this->assertSame($initial->id, SourceRuntimeState::query()->firstOrFail()->active_crawl_campaign_id);
    }

    public function test_two_paused_regular_scopes_can_take_turns(): void
    {
        $manager = app(CrawlCampaignManager::class);
        $first = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'status' => CrawlCampaignStatus::Pending,
        ]);
        $second = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Regular,
            'status' => CrawlCampaignStatus::Pending,
        ]);

        $manager->start($first);
        $manager->pause($first);
        $manager->start($second);

        $this->assertSame(CrawlCampaignStatus::Paused, $first->refresh()->status);
        $this->assertSame(CrawlCampaignStatus::Running, $second->refresh()->status);
        $this->assertSame($second->id, SourceRuntimeState::query()->firstOrFail()->active_crawl_campaign_id);
    }
}
