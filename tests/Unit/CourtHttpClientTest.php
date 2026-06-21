<?php

namespace Tests\Unit;

use App\Parser\Fetcher\CourtHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CourtHttpClientTest extends TestCase
{
    public function test_decode_body_keeps_valid_utf8_even_when_meta_declares_windows_1251(): void
    {
        $body = '<meta http-equiv="Content-Type" content="text/html; charset=windows-1251"><title>Дело № 2-100/2025</title>';

        $this->assertSame($body, $this->decode($body, 'text/html'));
    }

    public function test_decode_body_converts_actual_windows_1251_bytes(): void
    {
        $body = mb_convert_encoding('<title>Дело № 2-100/2025</title>', 'Windows-1251', 'UTF-8');

        $this->assertSame('<title>Дело № 2-100/2025</title>', $this->decode($body, 'text/html; charset=windows-1251'));
    }

    private function decode(string $body, ?string $contentType): string
    {
        $method = new ReflectionMethod(CourtHttpClient::class, 'decodeBody');

        return $method->invoke(new CourtHttpClient, $body, $contentType);
    }
}
