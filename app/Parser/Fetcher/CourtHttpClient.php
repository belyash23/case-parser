<?php

namespace App\Parser\Fetcher;

use App\Admin\Services\ParserSettings;
use App\Models\Parser\Court;
use App\Models\Parser\ParserError;
use App\Models\Parser\ParserRun;
use App\Models\Parser\RequestLog;
use App\Parser\DTO\FetchResponse;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Services\AvailabilityCheckRecorder;
use App\Parser\Services\AvailabilityOutcomeClassifier;
use App\Parser\Services\SudrfSourceGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CourtHttpClient
{
    public function __construct(
        private readonly AvailabilityCheckRecorder $availabilityRecorder,
        private readonly AvailabilityOutcomeClassifier $outcomeClassifier,
        private readonly SudrfSourceGuard $sourceGuard,
        private readonly ParserSettings $settings,
    ) {}

    public function fetch(Court $court, string $url, ?ParserRun $run = null): FetchResponse
    {
        $maxAttempts = 1 + (int) $court->retry_count;
        $timeoutSeconds = max(1, (int) ceil(((int) $court->timeout_ms) / 1000));
        $connectTimeoutSeconds = min(
            $timeoutSeconds,
            max(1, $this->settings->current()->connect_timeout_seconds),
        );
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $this->sourceGuard->reserveRequestSlot();
            $attempt++;
            $started = hrtime(true);

            try {
                $request = Http::withHeaders([
                    'User-Agent' => config('parser.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                    ->connectTimeout($connectTimeoutSeconds)
                    ->timeout($timeoutSeconds);

                if (! (bool) config('parser.verify_tls', false)) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get($url);
                $durationMs = (int) round((hrtime(true) - $started) / 1000000);
                $rawBody = $response->body();
                $body = $this->decodeBody($rawBody, $response->header('Content-Type'));
                $status = $response->status();

                $this->sourceGuard->recordHttpResponse(
                    $status,
                    $this->outcomeClassifier->retryAfterSeconds($response->header('Retry-After')),
                );

                $requestLog = $this->logRequest($court, $url, $run, $status, $durationMs, strlen($rawBody), $attempt - 1);

                if ($run !== null) {
                    $run->increment('total_requests');
                    $run->increment($status >= 200 && $status < 400 ? 'successful_requests' : 'failed_requests');
                }

                if ($status >= 400) {
                    $this->recordParserError($court, $url, $run, 'HTTP_'.$status, 'HTTP status '.$status);
                    $this->availabilityRecorder->fromRequestLog($requestLog);
                }

                return new FetchResponse($url, $status, $body, hash('sha256', $rawBody), $durationMs, strlen($rawBody), $attempt - 1);
            } catch (SourceCircuitOpenException $exception) {
                throw $exception;
            } catch (ConnectionException $exception) {
                $lastError = $exception;
                $this->rememberFailedAttempt($court, $url, $run, $attempt, $started, $exception);
            } catch (Throwable $exception) {
                throw $exception;
            }
        }

        $message = $lastError?->getMessage() ?? 'Unknown fetch error';
        $type = $this->isTimeoutMessage($message) ? 'TIMEOUT' : 'NETWORK_ERROR';
        $this->recordParserError($court, $url, $run, $type, $message, $lastError?->getTraceAsString());

        if ($run !== null) {
            $run->increment('error_count');
        }

        throw $lastError ?? new RuntimeException($message);
    }

    private function rememberFailedAttempt(Court $court, string $url, ?ParserRun $run, int $attempt, int $started, Throwable $exception): void
    {
        $isTimeout = $this->isTimeout($exception);
        $type = $isTimeout ? 'TIMEOUT' : 'NETWORK_ERROR';
        $durationMs = (int) round((hrtime(true) - $started) / 1000000);
        $this->sourceGuard->recordConnectionFailure($isTimeout);
        $requestLog = $this->logRequest(
            $court,
            $url,
            $run,
            null,
            $durationMs,
            null,
            $attempt - 1,
            $type,
            $exception->getMessage(),
        );
        $this->availabilityRecorder->fromRequestLog($requestLog);

        if ($run !== null) {
            $run->increment('total_requests');
            $run->increment('failed_requests');
        }
    }

    private function decodeBody(string $body, ?string $contentType): string
    {
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        $contentType = mb_strtolower($contentType ?? '');

        if (str_contains($contentType, 'windows-1251') || str_contains($body, 'charset=windows-1251')) {
            return mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
        }

        return mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
    }

    private function isTimeout(Throwable $exception): bool
    {
        return str_contains(
            $this->outcomeClassifier->fromError($exception::class, $exception->getMessage()),
            'timeout',
        );
    }

    private function isTimeoutMessage(string $message): bool
    {
        return str_contains($this->outcomeClassifier->fromError(null, $message), 'timeout');
    }

    private function logRequest(Court $court, string $url, ?ParserRun $run, ?int $statusCode, ?int $durationMs, ?int $responseSizeBytes, int $retryCount, ?string $errorType = null, ?string $errorMessage = null): RequestLog
    {
        return RequestLog::query()->create([
            'parser_run_id' => $run?->id,
            'court_id' => $court->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'response_size_bytes' => $responseSizeBytes,
            'retry_count' => $retryCount,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ]);
    }

    private function recordParserError(Court $court, string $url, ?ParserRun $run, string $type, ?string $message, ?string $traceback = null): void
    {
        ParserError::query()->create([
            'parser_run_id' => $run?->id,
            'court_id' => $court->id,
            'url' => $url,
            'error_type' => $type,
            'error_message' => $message,
            'traceback' => $traceback,
            'occurred_at' => now(),
        ]);
    }
}
