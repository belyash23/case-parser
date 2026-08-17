<?php

namespace Tests\Feature\Parser;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use App\Enums\Parser\RegularCrawlStopReason;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCase;
use App\Models\Parser\CourtCrawlState;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserRun;
use App\Models\Parser\ParserSetting;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Services\CrawlCampaignManager;
use App\Parser\Services\RegularCrawlBudgetAllocator;
use App\Parser\Services\RegularCrawlService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\AdvancingRequestSleeper;
use Tests\TestCase;

class RegularCrawlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-16 12:00:00');
        config()->set('parser.sudrf.minimum_request_interval_ms', 1000);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->instance(RequestSleeper::class, new AdvancingRequestSleeper);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_cycle_budget_uses_the_configured_interval_but_reserves_capacity(): void
    {
        config()->set('parser.sudrf.minimum_request_interval_ms', 10000);
        config()->set('parser.regular.capacity_utilization_percent', 80);

        $budget = app(RegularCrawlBudgetAllocator::class)->cycleBudget(CarbonImmutable::parse('2026-08-01'));

        $this->assertSame(214272, $budget);
    }

    public function test_allocator_enforces_the_court_share_and_redistributes_the_remainder(): void
    {
        $settings = ParserSetting::current();
        $settings->forceFill([
            'base_budget_percent' => 0,
            'maximum_court_share_percent' => 40,
        ])->save();
        $this->assertSame(40, $settings->refresh()->maximum_court_share_percent);
        $largeCourt = $this->court('Large court', 'https://large.example.sudrf.ru');
        $firstSmallCourt = $this->court('First small court', 'https://small-one.example.sudrf.ru');
        $secondSmallCourt = $this->court('Second small court', 'https://small-two.example.sudrf.ru');
        CourtCrawlState::query()->create([
            'court_id' => $largeCourt->id,
            'stats_json' => [
                'initial_calendar_days' => 1,
                'initial_case_links' => 1000,
            ],
        ]);
        CourtCrawlState::query()->create(['court_id' => $firstSmallCourt->id]);
        CourtCrawlState::query()->create(['court_id' => $secondSmallCourt->id]);
        $courts = Court::query()->with('crawlState')->orderBy('id')->get();
        $this->assertSame(40, app(ParserSettings::class)->current()->maximum_court_share_percent);

        $allocation = app(RegularCrawlBudgetAllocator::class)->allocateCourts($courts, 100);

        $this->assertSame(100, array_sum($allocation['budgets']));
        $this->assertLessThanOrEqual(40, max($allocation['budgets']));
        $this->assertGreaterThanOrEqual($allocation['budgets'][$firstSmallCourt->id], $allocation['budgets'][$largeCourt->id]);
        $this->assertGreaterThanOrEqual($allocation['budgets'][$secondSmallCourt->id], $allocation['budgets'][$largeCourt->id]);
        $this->assertSame([40, 40, 40], array_values($allocation['hard_caps']));
    }

    public function test_regular_crawl_rotates_courts_after_each_calendar_day(): void
    {
        $firstCourt = $this->court('First court', 'https://first.example.sudrf.ru');
        $secondCourt = $this->court('Second court', 'https://second.example.sudrf.ru');
        Http::fake(['*' => Http::response('<!doctype html><html><body></body></html>', 200)]);
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$firstCourt, $secondCourt]));
        $service->prepareCycle($campaign, collect([$firstCourt, $secondCourt]));
        $run = $this->parserRun($campaign);

        $result = $service->run($campaign, $run, maximumRequests: 2);
        $calendarUrls = Http::recorded()->map(fn (array $record): string => $record[0]->url())->all();

        $this->assertSame(RegularCrawlStopReason::RequestLimit, $result->reason);
        $this->assertSame([
            $firstCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=31.07.2026',
            $secondCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=31.07.2026',
        ], $calendarUrls);
        $this->assertSame('2026-07-30', CourtCrawlState::query()->whereBelongsTo($firstCourt)->firstOrFail()->head_cursor_date?->toDateString());
        $this->assertSame('2026-07-30', CourtCrawlState::query()->whereBelongsTo($secondCourt)->firstOrFail()->head_cursor_date?->toDateString());
    }

    public function test_same_case_number_is_enqueued_once_per_monthly_cycle(): void
    {
        $court = $this->court('Dedupe court', 'https://dedupe.example.sudrf.ru');
        Http::fake(['*' => Http::response($this->calendarHtml(), 200)]);
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$court]));
        $service->prepareCycle($campaign, collect([$court]));

        $service->run($campaign, $this->parserRun($campaign), maximumRequests: 2);

        $this->assertSame(
            1,
            CrawlWorkItem::query()->where('work_type', CrawlWorkType::CaseCard)->count(),
        );
    }

    public function test_unfinished_head_work_becomes_backlog_when_the_next_cycle_starts(): void
    {
        $court = $this->court('Backlog court', 'https://backlog.example.sudrf.ru');
        Http::fake(['*' => Http::response('<!doctype html><html><body></body></html>', 200)]);
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$court]));
        $service->prepareCycle($campaign, collect([$court]));
        $service->run($campaign, $this->parserRun($campaign), maximumRequests: 1);

        CarbonImmutable::setTestNow('2026-09-01 00:00:00');
        $service->prepareCycle($campaign->refresh(), collect([$court]));

        $backlog = CrawlWorkItem::query()->where('work_type', CrawlWorkType::BacklogDrain)->firstOrFail();
        $head = CrawlWorkItem::query()->where('work_type', CrawlWorkType::HeadSync)->firstOrFail();
        $state = CourtCrawlState::query()->whereBelongsTo($court)->firstOrFail();
        $this->assertSame('2026-07-30', $backlog->payload_json['cursor_date']);
        $this->assertSame('2026-08-31', $head->payload_json['cursor_date']);
        $this->assertTrue($state->has_backlog);
        $this->assertSame(0, $campaign->refresh()->requests_used);
    }

    public function test_overdue_active_case_is_rechecked_before_calendar_work(): void
    {
        $court = $this->court('Recheck court', 'https://recheck.example.sudrf.ru');
        $caseUrl = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&name_op=case&case_id=100&case_uid=uid-100&delo_id=1540005';
        $courtCase = CourtCase::query()->create([
            'primary_court_id' => $court->id,
            'normalized_case_number' => '2-100/2026',
            'dispute_status' => 'active',
        ]);
        $instance = CaseInstance::query()->create([
            'case_id' => $courtCase->id,
            'court_id' => $court->id,
            'source_url' => $caseUrl,
            'source_url_hash' => hash('sha256', $caseUrl),
            'case_uid' => 'uid-100',
            'external_case_id' => '100',
            'source_case_type_id' => '1540005',
            'instance_level' => 'first',
            'court_instance_status_normalized' => 'active',
            'dispute_status_normalized' => 'active',
        ]);
        CaseInstance::query()->whereKey($instance->id)->update(['updated_at' => CarbonImmutable::parse('2026-05-01')]);
        $caseHtml = file_get_contents(base_path('tests/Fixtures/sudrf/case.html'));
        Http::fake(['*' => Http::response($caseHtml, 200)]);
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$court]));
        $service->prepareCycle($campaign, collect([$court]));

        $service->run($campaign, $this->parserRun($campaign), maximumRequests: 1);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $caseUrl);
        $this->assertSame(
            CrawlWorkStatus::Completed,
            CrawlWorkItem::query()->where('work_type', CrawlWorkType::Recheck)->firstOrFail()->status,
        );
    }

    public function test_global_capacity_plan_does_not_stop_a_cycle_with_remaining_work(): void
    {
        $court = $this->court('Capacity court', 'https://capacity.example.sudrf.ru');
        Http::fake(['*' => Http::response('<!doctype html><html><body></body></html>', 200)]);
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$court]));
        $service->prepareCycle($campaign, collect([$court]));
        $campaign->forceFill(['request_budget' => 1])->save();

        $result = $service->run($campaign, $this->parserRun($campaign), maximumRequests: 2);

        $this->assertSame(RegularCrawlStopReason::RequestLimit, $result->reason);
        $this->assertSame(2, $result->requests);
        $this->assertSame(2, $campaign->refresh()->requests_used);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_does_not_consume_a_regular_case_attempt(): void
    {
        ParserSetting::current()->update([
            'regular_maximum_case_attempts' => 1,
            'rate_limit_cooldown_seconds' => 60,
        ]);
        $court = $this->court('Rate limited court', 'https://limited.example.sudrf.ru');
        $caseUrl = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&name_op=case&case_id=100&case_uid=uid-100&delo_id=1540005';
        $service = app(RegularCrawlService::class);
        $campaign = $service->createCampaign(collect([$court]));
        $service->prepareCycle($campaign, collect([$court]));
        $campaign->workItems()->delete();
        $caseWork = CrawlWorkItem::query()->create([
            'crawl_campaign_id' => $campaign->id,
            'court_id' => $court->id,
            'work_type' => CrawlWorkType::CaseCard,
            'status' => CrawlWorkStatus::Pending,
            'deduplication_key' => hash('sha256', 'rate-limited-case'),
            'target_date' => now()->toDateString(),
            'priority' => 100,
            'available_at' => now(),
            'payload_json' => ['url' => $caseUrl],
        ]);
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '60'])]);

        try {
            $service->run($campaign, $this->parserRun($campaign));
            $this->fail('The source circuit should stop the campaign.');
        } catch (SourceCircuitOpenException) {
            $this->assertSame(CrawlWorkStatus::Pending, $caseWork->refresh()->status);
            $this->assertSame(0, $caseWork->attempts);
            $this->assertNull($caseWork->finished_at);
        }
    }

    public function test_scheduled_regular_command_is_a_no_op_while_initial_campaign_is_active(): void
    {
        $initial = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Pending,
        ]);
        $manager = app(CrawlCampaignManager::class);
        $manager->start($initial);
        $manager->pause($initial);
        Http::fake();

        $this->artisan('parser:crawl-regular', ['--scheduled' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, CrawlCampaign::query()->count());
        $this->assertSame(CrawlCampaignStatus::Paused, $initial->refresh()->status);
    }

    public function test_regular_command_can_create_a_campaign_for_selected_courts(): void
    {
        $selectedCourt = $this->court('Selected court', 'https://selected.example.sudrf.ru');
        $this->court('Other court', 'https://other.example.sudrf.ru');
        Http::fake(['*' => Http::response('<!doctype html><html><body></body></html>', 200)]);

        $this->artisan('parser:crawl-regular', [
            '--court' => [(string) $selectedCourt->id],
            '--skip-directory-sync' => true,
            '--max-requests' => '1',
        ])->assertExitCode(0);

        $campaign = CrawlCampaign::query()->firstOrFail();
        $this->assertSame([$selectedCourt->id], $campaign->settings_json['court_ids']);
        $this->assertSame(CrawlCampaignStatus::Paused, $campaign->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $selectedCourt->base_url));
    }

    private function calendarHtml(): string
    {
        return <<<'HTML'
<!doctype html><html><body><table><tr>
<td>1.</td>
<td><a href="/modules.php?name=sud_delo&amp;srv_num=1&amp;name_op=case&amp;case_id=100&amp;case_uid=uid-100&amp;delo_id=1540005"><u>2-100/2026</u></a></td>
<td>10:30</td>
</tr></table></body></html>
HTML;
    }

    private function court(string $name, string $baseUrl): Court
    {
        return Court::query()->create([
            'name' => $name,
            'base_url' => $baseUrl,
            'retry_count' => 0,
        ]);
    }

    private function parserRun(CrawlCampaign $campaign): ParserRun
    {
        if ($campaign->refresh()->status !== CrawlCampaignStatus::Running) {
            app(CrawlCampaignManager::class)->start($campaign);
        }

        return ParserRun::query()->create([
            'crawl_campaign_id' => $campaign->id,
            'run_type' => 'regular_campaign_slice',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }
}
