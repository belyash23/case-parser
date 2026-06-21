<?php

namespace App\Parser\Fetcher;

use App\Models\Parser\Court;
use App\Models\Parser\ParserError;
use App\Models\Parser\ParserRun;
use App\Models\Parser\RequestLog;
use App\Parser\DTO\FetchResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CourtHttpClient
{
    public function fetch(Court $court, string $url, ?ParserRun $run = null): FetchResponse
    {
        $this->respectCourtInterval($court, (int) $court->min_request_interval_ms);

        $maxAttempts = 1 + (int) $court->retry_count;
        $timeoutSeconds = max(1, (int) ceil(((int) $court->timeout_ms) / 1000));
        $backoffMs = (int) $court->min_request_interval_ms;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $started = hrtime(true);

            try {
                $request = Http::withHeaders([
                    'User-Agent' => config('parser.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])->timeout($timeoutSeconds);

                if (! (bool) config('parser.verify_tls', false)) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get($url);
                $durationMs = (int) round((hrtime(true) - $started) / 1000000);
                $rawBody = $response->body();
                $body = $this->decodeBody($rawBody, $response->header('Content-Type'));
                $status = $response->status();

                $this->logRequest($court, $url, $run, $status, $durationMs, strlen($rawBody), $attempt - 1);

                if ($run !== null) {
                    $run->increment('total_requests');
                    $run->increment($status >= 200 && $status < 400 ? 'successful_requests' : 'failed_requests');
                }

                if ($status >= 400) {
                    $this->recordParserError($court, $url, $run, 'HTTP_'.$status, 'HTTP status '.$status);
                }

                return new FetchResponse($url, $status, $body, hash('sha256', $rawBody), $durationMs, strlen($rawBody), $attempt - 1);
            } catch (ConnectionException $exception) {
                $lastError = $exception;
            } catch (Throwable $exception) {
                $lastError = $exception;
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffMs * 1000);
                $backoffMs = (int) round($backoffMs * (float) $court->backoff_multiplier);
            }
        }

        $durationMs = isset($started) ? (int) round((hrtime(true) - $started) / 1000000) : null;
        $message = $lastError?->getMessage() ?? 'Unknown fetch error';
        $type = str_contains(mb_strtolower($message), 'timeout') ? 'TIMEOUT' : 'NETWORK_ERROR';

        $this->logRequest($court, $url, $run, null, $durationMs, null, max(0, $attempt - 1), $type, $message);
        $this->recordParserError($court, $url, $run, $type, $message, $lastError?->getTraceAsString());

        if ($run !== null) {
            $run->increment('total_requests');
            $run->increment('failed_requests');
            $run->increment('error_count');
        }

        throw $lastError ?? new RuntimeException($message);
    }

    private function respectCourtInterval(Court $court, int $intervalMs): void
    {
        $last = RequestLog::query()->where('court_id', $court->id)->latest('id')->first();

        if ($last === null || $intervalMs <= 0) {
            return;
        }

        $elapsedMs = (int) $last->created_at->diffInMilliseconds(now());
        if ($elapsedMs < $intervalMs) {
            usleep(($intervalMs - $elapsedMs) * 1000);
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

    private function logRequest(Court $court, string $url, ?ParserRun $run, ?int $statusCode, ?int $durationMs, ?int $responseSizeBytes, int $retryCount, ?string $errorType = null, ?string $errorMessage = null): void
    {
        RequestLog::query()->create([
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
