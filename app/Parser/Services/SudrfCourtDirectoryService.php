<?php

namespace App\Parser\Services;

use App\Models\Parser\Court;
use App\Parser\Support\Html;
use DOMElement;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SudrfCourtDirectoryService
{
    /**
     * @return array<int, Court>
     */
    public function syncRegion(int $regionId, ?string $regionName = null, ?string $city = null, bool $dryRun = false): array
    {
        $html = $this->fetchRegionHtml($regionId);
        $entries = $this->parseCourts($html);
        $courts = [];

        foreach ($entries as $entry) {
            if ($dryRun) {
                $court = new Court([
                    'name' => $entry['name'],
                    'region' => $regionName,
                    'city' => $city,
                    'court_level' => 'district',
                    'court_type' => 'municipal',
                    'source_type' => 'sudrf',
                    'base_url' => $entry['base_url'],
                    'layout_type' => 'sudrf_mobile',
                    'status' => 'active',
                ]);
            } else {
                $court = Court::query()->updateOrCreate(
                    ['base_url' => $entry['base_url']],
                    [
                        'name' => $entry['name'],
                        'region' => $regionName,
                        'city' => $city,
                        'court_level' => 'district',
                        'court_type' => 'municipal',
                        'source_type' => 'sudrf',
                        'layout_type' => 'sudrf_mobile',
                        'status' => 'active',
                        'is_enabled' => true,
                        'min_request_interval_ms' => 3000,
                        'max_parallel_requests' => 1,
                        'timeout_ms' => 30000,
                        'retry_count' => 2,
                        'backoff_multiplier' => 1.8,
                        'crawl_priority' => 100,
                    ],
                );
            }

            $courts[] = $court;
        }

        return $courts;
    }

    protected function fetchRegionHtml(int $regionId): string
    {
        $response = Http::withHeaders([
            'User-Agent' => config('parser.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ])->timeout(30)->get('https://sudrf.ru/index.php', [
            'id' => 300,
            'act' => 'go_search',
            'searchtype' => 'fs',
            'court_name' => '',
            'court_subj' => $regionId,
            'court_type' => 0,
            'court_okrug' => 0,
            'vcourt_okrug' => 0,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('SUDRF court directory returned HTTP '.$response->status());
        }

        return $this->decodeBody($response->body(), $response->header('Content-Type'));
    }

    /**
     * @return array<int, array{name: string, base_url: string}>
     */
    protected function parseCourts(string $html): array
    {
        $xpath = Html::xpath($html);
        $entries = [];
        $seen = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode($anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $baseUrl = $this->normalizeCourtBaseUrl($href);
            $name = Html::text($anchor);

            if ($baseUrl === null || $name === '' || isset($seen[$baseUrl])) {
                continue;
            }

            $seen[$baseUrl] = true;
            $entries[] = [
                'name' => $name,
                'base_url' => $baseUrl,
            ];
        }

        return $entries;
    }

    protected function normalizeCourtBaseUrl(string $href): ?string
    {
        if (! preg_match('~https?://[^/]*sudrf\.ru~i', $href, $matches)) {
            return null;
        }

        $host = parse_url($matches[0], PHP_URL_HOST);
        if (! is_string($host) || $host === '' || in_array($host, ['sudrf.ru', 'bsr.sudrf.ru'], true)) {
            return null;
        }

        return 'https://'.$host;
    }

    protected function decodeBody(string $body, ?string $contentType): string
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
}
