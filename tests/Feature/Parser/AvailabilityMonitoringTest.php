<?php

namespace Tests\Feature\Parser;

use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\AvailabilityIncident;
use App\Models\Parser\Court;
use App\Models\Parser\ParserSetting;
use App\Models\Parser\RequestLog;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Fetcher\CourtHttpClient;
use App\Parser\Services\AvailabilityMonitorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\AdvancingRequestSleeper;
use Tests\TestCase;

class AvailabilityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->instance(RequestSleeper::class, new AdvancingRequestSleeper);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_recent_calendar_request_is_reused_without_an_extra_http_request(): void
    {
        Http::fake();
        $court = $this->court();
        $url = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=15.08.2026';
        $requestLog = RequestLog::query()->create([
            'court_id' => $court->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'status_code' => 200,
            'duration_ms' => 125,
            'response_size_bytes' => 5000,
        ]);
        Court::query()->create([
            'name' => 'Other court',
            'base_url' => 'https://other.udm.sudrf.ru',
        ]);

        $this->artisan('monitor:sudrf', ['--force' => true])->assertExitCode(0);
        $check = AvailabilityCheck::query()->firstOrFail();

        Http::assertNothingSent();
        $this->assertSame($requestLog->id, $check->request_log_id);
        $this->assertSame('parser_reused', $check->source);
        $this->assertSame('success', $check->outcome);
        $this->assertDatabaseCount('availability_checks', 1);
    }

    public function test_rate_limit_opens_an_incident_and_global_circuit_until_recovery(): void
    {
        config()->set('monitoring.sudrf.failure_threshold', 2);
        config()->set('monitoring.sudrf.recovery_threshold', 2);
        ParserSetting::current()->update(['rate_limit_cooldown_seconds' => 60]);
        $statuses = [429, 200, 200];
        Http::fake(function () use (&$statuses) {
            $status = array_shift($statuses);

            return Http::response('', $status, $status === 429 ? ['Retry-After' => '60'] : []);
        });
        $court = $this->court();
        $monitor = app(AvailabilityMonitorService::class);

        $first = $monitor->check($court);
        $blocked = $monitor->check($court);

        $this->assertSame('rate_limited', $first->outcome);
        $this->assertSame(60, $first->retry_after_seconds);
        $this->assertSame('source_circuit_open', $blocked->outcome);
        Http::assertSentCount(1);

        $incident = AvailabilityIncident::query()->firstOrFail();
        $this->assertSame('open', $incident->status);
        $this->assertSame(2, $incident->failed_checks);
        $this->assertSame(2, $incident->consecutive_failures);

        CarbonImmutable::setTestNow(now()->addSeconds(60));
        $monitor->check($court);
        $monitor->check($court);

        Http::assertSentCount(3);
        $this->assertSame('resolved', $incident->refresh()->status);
        $this->assertSame(2, $incident->successful_checks);
        $this->assertSame(2, $incident->consecutive_successes);
    }

    public function test_parser_http_error_is_recorded_without_a_monitor_probe(): void
    {
        Http::fake([
            '*' => Http::response('', 403),
        ]);
        $court = $this->court();
        $url = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=15.08.2026';

        app(CourtHttpClient::class)->fetch($court, $url);

        Http::assertSentCount(1);
        $check = AvailabilityCheck::query()->firstOrFail();
        $this->assertSame('parser', $check->source);
        $this->assertSame('case_list', $check->endpoint_type);
        $this->assertSame('forbidden', $check->outcome);
        $this->assertSame(403, $check->http_status);
    }

    public function test_every_failed_http_attempt_is_recorded(): void
    {
        ParserSetting::current()->update(['timeout_circuit_threshold' => 3]);
        Http::fake([
            '*' => Http::failedConnection('Operation timed out after 30001 milliseconds'),
        ]);
        $court = $this->court();
        $court->update(['retry_count' => 2]);
        $url = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=15.08.2026';

        try {
            app(CourtHttpClient::class)->fetch($court, $url);
            $this->fail('A connection exception was expected.');
        } catch (ConnectionException) {
            Http::assertSentCount(3);
            $this->assertSame(3, RequestLog::query()->count());
            $this->assertSame(3, AvailabilityCheck::query()->count());
        }
    }

    public function test_checks_can_be_exported_to_csv(): void
    {
        Storage::fake('local');
        $court = $this->court();
        AvailabilityCheck::query()->create([
            'court_id' => $court->id,
            'source' => 'scheduled_probe',
            'endpoint_type' => 'case_list',
            'url' => $court->base_url.'/modules.php?name=sud_delo',
            'checked_at' => now(),
            'outcome' => 'forbidden',
            'http_status' => 403,
            'probe_node' => 'test-node',
        ]);

        $date = now()->toDateString();
        $this->artisan('monitor:export', [
            '--from' => $date,
            '--to' => $date,
            '--format' => 'csv',
            '--path' => 'exports/availability-test.csv',
        ])->assertExitCode(0);

        Storage::disk('local')->assertExists('exports/availability-test.csv');
        $content = Storage::disk('local')->get('exports/availability-test.csv');
        $this->assertStringContainsString('court_name', $content);
        $this->assertStringContainsString('forbidden', $content);
    }

    public function test_parser_connection_failure_is_recorded(): void
    {
        Http::fake([
            '*' => Http::failedConnection('Operation timed out after 30001 milliseconds'),
        ]);
        $court = $this->court();
        $url = $court->base_url.'/modules.php?name=sud_delo&srv_num=1&H_date=15.08.2026';

        try {
            app(CourtHttpClient::class)->fetch($court, $url);
            $this->fail('A connection exception was expected.');
        } catch (ConnectionException) {
            $check = AvailabilityCheck::query()->firstOrFail();
            $this->assertSame('parser', $check->source);
            $this->assertSame('read_timeout', $check->outcome);
            $this->assertNull($check->http_status);
        }
    }

    private function court(): Court
    {
        return Court::query()->create([
            'name' => 'Leninskiy court',
            'base_url' => 'https://leninskiy.udm.sudrf.ru',
            'min_request_interval_ms' => 0,
            'retry_count' => 0,
        ]);
    }
}
