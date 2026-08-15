<?php

namespace App\Parser\Services;

use Carbon\CarbonImmutable;

class AvailabilityOutcomeClassifier
{
    public function fromHttpStatus(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'success',
            $status === 403 => 'forbidden',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'http_5xx',
            $status >= 400 => 'http_4xx',
            default => 'unexpected_http_status',
        };
    }

    public function fromError(?string $errorType, ?string $message): string
    {
        $value = mb_strtolower(trim(($errorType ?? '').' '.($message ?? '')));

        return match (true) {
            str_contains($value, 'resolve') || str_contains($value, 'dns') => 'dns_error',
            str_contains($value, 'connect') && str_contains($value, 'timeout') => 'connect_timeout',
            str_contains($value, 'timeout') || str_contains($value, 'timed out') => 'read_timeout',
            str_contains($value, 'ssl') || str_contains($value, 'tls') || str_contains($value, 'certificate') => 'tls_error',
            default => 'network_error',
        };
    }

    public function retryAfterSeconds(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if (ctype_digit(trim($header))) {
            return (int) trim($header);
        }

        try {
            return (int) max(0, now()->diffInSeconds(CarbonImmutable::parse($header), false));
        } catch (\Throwable) {
            return null;
        }
    }
}
