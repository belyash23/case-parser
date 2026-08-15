<?php

namespace App\Parser\Services;

use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\Court;
use App\Models\Parser\RequestLog;
use App\Parser\DTO\AvailabilityProbeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class AvailabilityMonitorService
{
    public function __construct(
        private readonly AvailabilityCheckRecorder $recorder,
        private readonly AvailabilityOutcomeClassifier $classifier,
    ) {}

    /**
     * @param  array<int, int>  $courtIds
     * @return Collection<int, AvailabilityCheck>
     */
    public function reuseRecentParserActivity(array $courtIds): Collection
    {
        $windowMinutes = max(1, (int) config('monitoring.sudrf.reuse_parser_window_minutes', 10));

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
        $windowMinutes = max(1, (int) config('monitoring.sudrf.reuse_parser_window_minutes', 10));

        return RequestLog::query()
            ->where('court_id', $court->id)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();
    }

    private function probe(Court $court): AvailabilityProbeResult
    {
        $url = $this->caseListUrl($court);
        $startedAt = hrtime(true);

        try {
            $request = Http::withHeaders([
                'User-Agent' => config('parser.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
                ->connectTimeout(max(1, (int) config('monitoring.sudrf.connect_timeout_seconds', 10)))
                ->timeout(max(1, (int) config('monitoring.sudrf.timeout_seconds', 45)));

            if (! (bool) config('parser.verify_tls', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url);
            $body = $response->body();

            return new AvailabilityProbeResult(
                url: $url,
                outcome: $this->classifier->fromHttpStatus($response->status()),
                httpStatus: $response->status(),
                durationMs: $this->elapsedMilliseconds($startedAt),
                responseSizeBytes: strlen($body),
                retryAfterSeconds: $this->classifier->retryAfterSeconds($response->header('Retry-After')),
                errorType: $response->successful() ? null : 'HTTP_'.$response->status(),
                errorMessage: $response->successful() ? null : 'HTTP status '.$response->status(),
                responseHash: hash('sha256', $body),
            );
        } catch (ConnectionException $exception) {
            return $this->failedProbe($url, $startedAt, $exception);
        } catch (Throwable $exception) {
            return $this->failedProbe($url, $startedAt, $exception);
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
}
