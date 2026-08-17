<?php

namespace Tests\Feature\Parser;

use App\Enums\Parser\CrawlCampaignStatus;
use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCrawlState;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserRun;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Exceptions\CalendarRequestFailedException;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Services\CrawlCampaignManager;
use App\Parser\Services\InitialCrawlService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\AdvancingRequestSleeper;
use Tests\TestCase;

class InitialCrawlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-16 12:00:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->instance(RequestSleeper::class, new AdvancingRequestSleeper);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_initial_command_can_limit_a_new_campaign_to_selected_courts(): void
    {
        $selectedCourt = $this->court('Selected court', 'https://selected.example.sudrf.ru', 10);
        $this->court('Other court', 'https://other.example.sudrf.ru', 20);
        Http::fake(['*' => Http::response('<!doctype html><html><body></body></html>', 200)]);

        $this->artisan('parser:crawl-initial', [
            '--from' => '2025-03-01',
            '--to' => '2025-03-01',
            '--court' => [(string) $selectedCourt->id],
            '--skip-directory-sync' => true,
        ])->assertExitCode(0);

        $campaign = CrawlCampaign::query()->firstOrFail();
        $this->assertSame([$selectedCourt->id], $campaign->settings_json['court_ids']);
        $this->assertSame(CrawlCampaignStatus::Completed, $campaign->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $selectedCourt->base_url));
    }

    public function test_initial_command_rejects_an_invalid_court_filter_without_http_requests(): void
    {
        Http::fake();

        $this->artisan('parser:crawl-initial', [
            '--from' => '2025-03-01',
            '--to' => '2025-03-01',
            '--court' => ['invalid'],
            '--skip-directory-sync' => true,
        ])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, CrawlCampaign::query()->count());
    }

    public function test_initial_crawl_rotates_courts_by_month_in_reverse_order_and_fetches_each_case_number_once(): void
    {
        $firstCourt = $this->court('First court', 'https://first.example.sudrf.ru', 10);
        $secondCourt = $this->court('Second court', 'https://second.example.sudrf.ru', 20);
        $caseHtml = file_get_contents(base_path('tests/Fixtures/sudrf/case.html'));

        Http::fake(function (Request $request) use ($caseHtml) {
            if (str_contains($request->url(), 'name_op=case')) {
                return Http::response($caseHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $caseId = str_replace('.', '', (string) ($query['H_date'] ?? '100'));

            return Http::response($this->calendarHtml($caseId), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $service = app(InitialCrawlService::class);
        $campaign = $service->createCampaign(
            CarbonImmutable::parse('2025-02-28'),
            CarbonImmutable::parse('2025-03-02'),
            Court::query()->orderBy('crawl_priority')->get(),
        );
        app(CrawlCampaignManager::class)->start($campaign);
        $run = $this->parserRun($campaign->id);

        $service->run($campaign, $run);
        app(CrawlCampaignManager::class)->finish($campaign->refresh(), CrawlCampaignStatus::Completed);

        $calendarUrls = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'H_date='),
        )->values()->map(fn (array $record): string => $record[0]->url())->all();

        $this->assertSame([
            $firstCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=02.03.2025',
            $firstCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=01.03.2025',
            $secondCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=02.03.2025',
            $secondCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=01.03.2025',
            $firstCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=28.02.2025',
            $secondCourt->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=28.02.2025',
        ], $calendarUrls);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'name_op=case'))->count());
        $this->assertSame(4, CrawlWorkItem::query()->where('work_type', CrawlWorkType::InitialMonth)->where('status', CrawlWorkStatus::Completed)->count());
        $this->assertSame(2, CrawlWorkItem::query()->where('work_type', CrawlWorkType::CaseCard)->where('status', CrawlWorkStatus::Completed)->count());
        $this->assertSame(2, $run->refresh()->new_cases_count);
        $this->assertSame(2, CaseInstance::query()->where('source_url', 'like', '%case_id=02032025%')->count());
        $this->assertSame('2025-02-27', CourtCrawlState::query()->whereBelongsTo($firstCourt)->firstOrFail()->initial_cursor_date?->toDateString());
        $this->assertSame('2025-02-27', CourtCrawlState::query()->whereBelongsTo($secondCourt)->firstOrFail()->initial_cursor_date?->toDateString());
    }

    public function test_case_rate_limit_does_not_advance_the_calendar_cursor(): void
    {
        $court = $this->court('Rate limited court', 'https://limited.example.sudrf.ru', 10);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'name_op=case')) {
                return Http::response('', 429, ['Retry-After' => '60']);
            }

            return Http::response($this->calendarHtml('100'), 200);
        });
        $service = app(InitialCrawlService::class);
        $campaign = $service->createCampaign(
            CarbonImmutable::parse('2025-03-01'),
            CarbonImmutable::parse('2025-03-01'),
            collect([$court]),
        );
        $run = $this->parserRun($campaign->id);

        try {
            $service->run($campaign, $run);
            $this->fail('The source circuit should stop the campaign.');
        } catch (SourceCircuitOpenException) {
            $monthWork = CrawlWorkItem::query()->where('work_type', CrawlWorkType::InitialMonth)->firstOrFail();
            $caseWork = CrawlWorkItem::query()->where('work_type', CrawlWorkType::CaseCard)->firstOrFail();
            $this->assertSame('2025-03-01', $monthWork->payload_json['cursor_date']);
            $this->assertSame(CrawlWorkStatus::Failed, $monthWork->status);
            $this->assertSame(CrawlWorkStatus::Pending, $caseWork->status);
            $this->assertSame(0, $caseWork->attempts);
        }
    }

    public function test_resume_retries_the_failed_calendar_date_instead_of_skipping_it(): void
    {
        $court = $this->court('Only court', 'https://only.example.sudrf.ru', 10);
        $failedDateAttempts = 0;
        $resumedCalendarDates = [];

        Http::fake(function (Request $request) use (&$failedDateAttempts, &$resumedCalendarDates) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $calendarDate = $query['H_date'] ?? null;

            if ($calendarDate === '01.03.2025') {
                $failedDateAttempts++;

                if ($failedDateAttempts === 1) {
                    return Http::response('', 500);
                }

                $resumedCalendarDates[] = $calendarDate;
            }

            return Http::response('<!doctype html><html><body></body></html>', 200);
        });

        $service = app(InitialCrawlService::class);
        $campaign = $service->createCampaign(
            CarbonImmutable::parse('2025-03-01'),
            CarbonImmutable::parse('2025-03-02'),
            collect([$court]),
        );
        $firstRun = $this->parserRun($campaign->id);

        try {
            $service->run($campaign, $firstRun);
            $this->fail('The failed calendar response should pause traversal.');
        } catch (CalendarRequestFailedException) {
            $monthWork = CrawlWorkItem::query()->where('work_type', CrawlWorkType::InitialMonth)->firstOrFail();
            $this->assertSame(CrawlWorkStatus::Failed, $monthWork->status);
            $this->assertSame('2025-03-01', $monthWork->payload_json['cursor_date']);
            $this->assertSame(1, $failedDateAttempts);
        }

        $secondRun = $this->parserRun($campaign->id);
        $service->run($campaign->refresh(), $secondRun);

        $this->assertSame(['01.03.2025'], $resumedCalendarDates);
        $this->assertSame(CrawlWorkStatus::Completed, CrawlWorkItem::query()->where('work_type', CrawlWorkType::InitialMonth)->firstOrFail()->status);
        $this->assertSame('2025-02-28', CourtCrawlState::query()->whereBelongsTo($court)->firstOrFail()->initial_cursor_date?->toDateString());
    }

    public function test_initial_crawl_stops_before_the_next_request_when_campaign_is_paused(): void
    {
        $court = $this->court('Paused court', 'https://paused.example.sudrf.ru', 10);
        Http::fake();
        $service = app(InitialCrawlService::class);
        $campaign = $service->createCampaign(
            CarbonImmutable::parse('2025-03-01'),
            CarbonImmutable::parse('2025-03-01'),
            collect([$court]),
        );
        $manager = app(CrawlCampaignManager::class);
        $manager->start($campaign);
        $manager->pause($campaign->refresh());

        $completed = $service->run($campaign->refresh(), $this->parserRun($campaign->id, false));

        $this->assertFalse($completed);
        Http::assertNothingSent();
        $this->assertSame(
            CrawlWorkStatus::Pending,
            CrawlWorkItem::query()->where('work_type', CrawlWorkType::InitialMonth)->firstOrFail()->status,
        );
    }

    private function court(string $name, string $baseUrl, int $priority): Court
    {
        return Court::query()->create([
            'name' => $name,
            'base_url' => $baseUrl,
            'crawl_priority' => $priority,
            'retry_count' => 0,
        ]);
    }

    private function parserRun(int $campaignId, bool $startCampaign = true): ParserRun
    {
        $campaign = CrawlCampaign::query()->findOrFail($campaignId);
        if ($startCampaign && $campaign->status !== CrawlCampaignStatus::Running) {
            app(CrawlCampaignManager::class)->start($campaign);
        }

        return ParserRun::query()->create([
            'crawl_campaign_id' => $campaignId,
            'run_type' => 'initial_campaign_session',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    private function calendarHtml(string $caseId): string
    {
        return <<<HTML
<!doctype html><html><body><table><tr>
<td>1.</td>
<td><a href="/modules.php?name=sud_delo&amp;srv_num=1&amp;name_op=case&amp;case_id={$caseId}&amp;case_uid=uid-{$caseId}&amp;delo_id=1540005&amp;new=0"><u>2-100/2025</u></a></td>
<td>10:30</td>
</tr></table></body></html>
HTML;
    }
}
