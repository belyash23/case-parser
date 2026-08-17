<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use App\Enums\Parser\SourceCircuitStatus;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Exceptions\SourceCircuitOpenException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SudrfSourceGuard
{
    public const SOURCE_TYPE = 'sudrf';

    private const MINIMUM_INTERVAL_MS = 1000;

    private const DEFAULT_INTERVAL_MS = 10000;

    public function __construct(
        private readonly RequestSleeper $sleeper,
        private readonly ParserSettings $settings,
    ) {}

    public function reserveRequestSlot(): void
    {
        while (true) {
            $minimumIntervalMilliseconds = max(self::MINIMUM_INTERVAL_MS, $this->settings->current()->request_interval_ms);
            $waitMilliseconds = DB::transaction(function () use ($minimumIntervalMilliseconds): int {
                $state = $this->lockedState();
                $now = CarbonImmutable::now();
                $allowsHalfOpenProbe = false;

                if ($state->circuit_status === SourceCircuitStatus::HalfOpen) {
                    throw $this->circuitException($state);
                }

                if ($state->circuit_status === SourceCircuitStatus::Open) {
                    if ($state->cooldown_until === null || $state->cooldown_until->isFuture()) {
                        throw $this->circuitException($state);
                    }

                    $state->circuit_status = SourceCircuitStatus::HalfOpen;
                    $allowsHalfOpenProbe = true;
                }

                if (! $allowsHalfOpenProbe && $state->next_request_at !== null && $state->next_request_at->gt($now)) {
                    return (int) ceil($now->diffInMilliseconds($state->next_request_at));
                }

                $intervalMilliseconds = $minimumIntervalMilliseconds;

                $state->forceFill([
                    'last_request_started_at' => $now,
                    'next_request_at' => $now->addMilliseconds($intervalMilliseconds),
                ])->save();

                return 0;
            }, 5);

            if ($waitMilliseconds <= 0) {
                return;
            }

            $this->sleeper->sleepMilliseconds($waitMilliseconds);
        }
    }

    public function ensureCircuitAllowsRequests(): void
    {
        $state = SourceRuntimeState::query()->where('source_type', self::SOURCE_TYPE)->first();

        if (! $state instanceof SourceRuntimeState) {
            return;
        }

        if ($state->circuit_status === SourceCircuitStatus::HalfOpen) {
            throw $this->circuitException($state);
        }

        if ($state->circuit_status === SourceCircuitStatus::Open
            && ($state->cooldown_until === null || $state->cooldown_until->isFuture())) {
            throw $this->circuitException($state);
        }
    }

    public function recordHttpResponse(int $statusCode, ?int $retryAfterSeconds = null): void
    {
        if ($statusCode === 403) {
            $this->openCircuit(
                'http_403',
                max(1, $this->settings->current()->forbidden_cooldown_seconds),
            );

            return;
        }

        if ($statusCode === 429) {
            $this->openCircuit(
                'http_429',
                max(
                    max(1, $this->settings->current()->rate_limit_cooldown_seconds),
                    $retryAfterSeconds ?? 0,
                ),
            );

            return;
        }

        $this->recordReachableResponse();
    }

    public function recordConnectionFailure(bool $isTimeout): void
    {
        $settings = $this->settings->current();

        DB::transaction(function () use ($isTimeout, $settings): void {
            $state = $this->lockedState();
            $state->last_failure_at = now();

            if ($state->circuit_status === SourceCircuitStatus::HalfOpen) {
                $state->forceFill([
                    'circuit_status' => SourceCircuitStatus::Open,
                    'circuit_opened_at' => now(),
                    'cooldown_until' => now()->addSeconds(max(1, $settings->timeout_cooldown_seconds)),
                    'circuit_reason' => $isTimeout ? 'half_open_timeout' : 'half_open_network_failure',
                ])->save();

                return;
            }

            if (! $isTimeout) {
                $state->consecutive_timeouts = 0;
                $state->save();

                return;
            }

            $state->consecutive_timeouts++;
            $threshold = max(1, $settings->timeout_circuit_threshold);

            if ($state->consecutive_timeouts >= $threshold) {
                $state->forceFill([
                    'circuit_status' => SourceCircuitStatus::Open,
                    'circuit_opened_at' => now(),
                    'cooldown_until' => now()->addSeconds(max(1, $settings->timeout_cooldown_seconds)),
                    'circuit_reason' => 'consecutive_timeouts',
                ]);
            }

            $state->save();
        }, 5);
    }

    private function recordReachableResponse(): void
    {
        DB::transaction(function (): void {
            $state = $this->lockedState();
            $state->forceFill([
                'circuit_status' => SourceCircuitStatus::Closed,
                'circuit_opened_at' => null,
                'cooldown_until' => null,
                'circuit_reason' => null,
                'consecutive_timeouts' => 0,
                'last_success_at' => now(),
            ])->save();
        }, 5);
    }

    private function openCircuit(string $reason, int $cooldownSeconds): void
    {
        DB::transaction(function () use ($reason, $cooldownSeconds): void {
            $state = $this->lockedState();
            $state->forceFill([
                'circuit_status' => SourceCircuitStatus::Open,
                'circuit_opened_at' => now(),
                'cooldown_until' => now()->addSeconds($cooldownSeconds),
                'circuit_reason' => $reason,
                'last_failure_at' => now(),
            ])->save();
        }, 5);
    }

    private function lockedState(): SourceRuntimeState
    {
        SourceRuntimeState::query()->firstOrCreate(
            ['source_type' => self::SOURCE_TYPE],
            ['circuit_status' => SourceCircuitStatus::Closed],
        );

        return SourceRuntimeState::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function circuitException(SourceRuntimeState $state): SourceCircuitOpenException
    {
        return new SourceCircuitOpenException(
            self::SOURCE_TYPE,
            $state->cooldown_until,
            $state->circuit_reason,
        );
    }
}
