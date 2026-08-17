<?php

namespace App\Parser\Services;

use App\Admin\Services\ParserSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SudrfDirectoryHttpClient
{
    public function __construct(
        private readonly SudrfSourceGuard $sourceGuard,
        private readonly AvailabilityOutcomeClassifier $outcomeClassifier,
        private readonly ParserSettings $settings,
    ) {}

    /** @param array<string, scalar|null> $query */
    public function get(string $url, array $query = []): string
    {
        $this->sourceGuard->reserveRequestSlot();

        try {
            $settings = $this->settings->current();
            $request = Http::withHeaders([
                'User-Agent' => config('parser.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
                ->connectTimeout(max(1, $settings->connect_timeout_seconds))
                ->timeout(max(1, $settings->directory_timeout_seconds));

            if (! (bool) config('parser.verify_tls', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url, $query);
            $this->sourceGuard->recordHttpResponse(
                $response->status(),
                $this->outcomeClassifier->retryAfterSeconds($response->header('Retry-After')),
            );

            if (! $response->successful()) {
                throw new RuntimeException('SUDRF directory returned HTTP '.$response->status());
            }

            return $this->decodeBody($response->body(), $response->header('Content-Type'));
        } catch (ConnectionException $exception) {
            $this->sourceGuard->recordConnectionFailure($this->isTimeout($exception));

            throw $exception;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    private function decodeBody(string $body, ?string $contentType): string
    {
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        return mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
    }

    private function isTimeout(Throwable $exception): bool
    {
        return str_contains($this->outcomeClassifier->fromError($exception::class, $exception->getMessage()), 'timeout');
    }
}
