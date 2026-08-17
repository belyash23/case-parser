<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\Court;
use App\Models\Parser\RequestLog;
use App\Parser\DTO\AvailabilityProbeResult;
use App\Parser\Exceptions\SourceCircuitOpenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class AvailabilityMonitorService
{
    public function __construct(
        private readonly AvailabilityCheckRecorder $recorder,
        private readonly AvailabilityOutcomeClassifier $classifier,
        private readonly SudrfSourceGuard $sourceGuard,
        private readonly ParserSettings $settings,
    ) {}

    /**
     * @param  array<int, int>  $courtIds
     * @return Collection<int, AvailabilityCheck>
     */
    public function reuseRecentParserActivity(array $courtIds): Collection
    {
        $windowMinutes = max(1, $this->settings->current()->monitor_reuse_window_minutes);

        return RequestLog::query()
            ->whereIn('court_id', $courtIds)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->latest('id')
            ->get()
            ->unique('court_id')
            ->map(fn (RequestLog $requestLog): AvailabilityCheck => $this->recorder->fromRequestLog($requestLog, 'parser_reused'))
            ->values();
    }

    public function check(Court $court): AvailabilityCheck
    {
        $recentRequest = $this->recentParserRequest($court);

        if ($recentRequest instanceof RequestLog) {
            return $this->recorder->fromRequestLog($recentRequest, 'parser_reused');
        }

        return $this->recorder->fromProbe($court, $this->probe($court));
    }

    private function recentParserRequest(Court $court): ?RequestLog
    {
        $windowMinutes = max(1, $this->settings->current()->monitor_reuse_window_minutes);

        return RequestLog::query()
            ->where('court_id', $court->id)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();
    }

    private function probe(Court $court): AvailabilityProbeResult
    {
        $url = $this->caseListUrl($court);

        try {
            $this->sourceGuard->reserveRequestSlot();
            $startedAt = hrtime(true);
            $settings = $this->settings->current();
            $request = Http::withHeaders([
                'User-Agent' => config('parser.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
                ->connectTimeout(max(1, $settings->monitor_connect_timeout_seconds))
                ->timeout(max(1, $settings->monitor_timeout_seconds));

            if (! (bool) config('parser.verify_tls', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url);
            $body = $response->body();
            $retryAfterSeconds = $this->classifier->retryAfterSeconds($response->header('Retry-After'));
            $this->sourceGuard->recordHttpResponse($response->status(), $retryAfterSeconds);

            return new AvailabilityProbeResult(
                url: $url,
                outcome: $this->classifier->fromHttpStatus($response->status()),
                httpStatus: $response->status(),
                durationMs: $this->elapsedMilliseconds($startedAt),
                responseSizeBytes: strlen($body),
                retryAfterSeconds: $retryAfterSeconds,
                errorType: $response->successful() ? null : 'HTTP_'.$response->status(),
                errorMessage: $response->successful() ? null : 'HTTP status '.$response->status(),
                responseHash: hash('sha256', $body),
            );
        } catch (SourceCircuitOpenException $exception) {
            return new AvailabilityProbeResult(
                url: $url,
                outcome: 'source_circuit_open',
                httpStatus: null,
                durationMs: null,
                responseSizeBytes: null,
                retryAfterSeconds: $exception->cooldownUntil !== null
                    ? (int) max(0, now()->diffInSeconds($exception->cooldownUntil, false))
                    : null,
                errorType: class_basename($exception),
                errorMessage: $exception->getMessage(),
                responseHash: null,
            );
        } catch (ConnectionException $exception) {
            $this->sourceGuard->recordConnectionFailure($this->isTimeout($exception));

            return $this->failedProbe($url, $startedAt ?? hrtime(true), $exception);
        } catch (Throwable $exception) {
            return $this->failedProbe($url, $startedAt ?? hrtime(true), $exception);
        }
    }

    private function failedProbe(string $url, int $startedAt, Throwable $exception): AvailabilityProbeResult
    {
        return new AvailabilityProbeResult(
            url: $url,
            outcome: $this->classifier->fromError($exception::class, $exception->getMessage()),
            httpStatus: null,
            durationMs: $this->elapsedMilliseconds($startedAt),
            responseSizeBytes: null,
            retryAfterSeconds: null,
            errorType: class_basename($exception),
            errorMessage: $exception->getMessage(),
            responseHash: null,
        );
    }

    private function caseListUrl(Court $court): string
    {
        return rtrim($court->base_url, '/').'/modules.php?'.http_build_query([
            'name' => 'sud_delo',
            'srv_num' => 1,
            'H_date' => now()->format('d.m.Y'),
        ]);
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1000000);
    }

    private function isTimeout(Throwable $exception): bool
    {
        return str_contains($this->classifier->fromError($exception::class, $exception->getMessage()), 'timeout');
    }
}
