<?php

namespace Tests\Feature\Parser;

use App\Enums\Parser\SourceCircuitStatus;
use App\Models\Parser\ParserSetting;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Services\SudrfSourceGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\AdvancingRequestSleeper;
use Tests\TestCase;

class SudrfSourceGuardTest extends TestCase
{
    use RefreshDatabase;

    private AdvancingRequestSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper = new AdvancingRequestSleeper;
        $this->app->instance(RequestSleeper::class, $this->sleeper);
        CarbonImmutable::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_default_request_interval_remains_ten_seconds(): void
    {
        $this->assertSame(10000, config('parser.sudrf.minimum_request_interval_ms'));
    }

    public function test_it_enforces_a_global_minimum_of_one_second(): void
    {
        ParserSetting::current()->update(['request_interval_ms' => 1000]);
        $guard = app(SudrfSourceGuard::class);

        $guard->reserveRequestSlot();
        $state = SourceRuntimeState::query()->firstOrFail();

        $this->assertSame('2026-08-15 12:00:00.000', $state->last_request_started_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-08-15 12:00:01.000', $state->next_request_at->format('Y-m-d H:i:s.v'));

        $guard->reserveRequestSlot();
        $state->refresh();

        $this->assertSame([1000], $this->sleeper->sleeps);
        $this->assertSame('2026-08-15 12:00:01.000', $state->last_request_started_at->format('Y-m-d H:i:s.v'));
    }

    public function test_rate_limit_opens_the_circuit_and_allows_one_probe_after_cooldown(): void
    {
        ParserSetting::current()->update(['rate_limit_cooldown_seconds' => 60]);
        $guard = app(SudrfSourceGuard::class);
        $guard->recordHttpResponse(429, 120);
        $state = SourceRuntimeState::query()->firstOrFail();

        $this->assertSame(SourceCircuitStatus::Open, $state->circuit_status);
        $this->assertSame('2026-08-15 12:02:00', $state->cooldown_until->format('Y-m-d H:i:s'));

        try {
            $guard->reserveRequestSlot();
            $this->fail('An open circuit should reject requests.');
        } catch (SourceCircuitOpenException $exception) {
            $this->assertSame('http_429', $exception->reason);
        }

        CarbonImmutable::setTestNow('2026-08-15 12:02:00');
        $guard->reserveRequestSlot();
        $this->assertSame(SourceCircuitStatus::HalfOpen, $state->refresh()->circuit_status);

        $guard->recordHttpResponse(200);
        $this->assertSame(SourceCircuitStatus::Closed, $state->refresh()->circuit_status);
    }

    public function test_a_different_connection_failure_resets_the_timeout_sequence(): void
    {
        ParserSetting::current()->update(['timeout_circuit_threshold' => 2]);
        $guard = app(SudrfSourceGuard::class);

        $guard->recordConnectionFailure(true);
        $guard->recordConnectionFailure(false);
        $guard->recordConnectionFailure(true);

        $state = SourceRuntimeState::query()->firstOrFail();
        $this->assertSame(1, $state->consecutive_timeouts);
        $this->assertSame(SourceCircuitStatus::Closed, $state->circuit_status);
    }

    public function test_consecutive_timeouts_open_the_circuit(): void
    {
        ParserSetting::current()->update(['timeout_circuit_threshold' => 2]);
        ParserSetting::current()->update(['timeout_cooldown_seconds' => 60]);
        $guard = app(SudrfSourceGuard::class);

        $guard->recordConnectionFailure(true);
        $guard->recordConnectionFailure(true);

        $state = SourceRuntimeState::query()->firstOrFail();
        $this->assertSame(2, $state->consecutive_timeouts);
        $this->assertSame(SourceCircuitStatus::Open, $state->circuit_status);
        $this->expectException(SourceCircuitOpenException::class);

        $guard->reserveRequestSlot();
    }
}
