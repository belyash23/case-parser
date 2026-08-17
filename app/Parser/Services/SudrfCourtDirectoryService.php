<?php

namespace App\Parser\Services;

use App\Models\Parser\Court;
use App\Models\Parser\Region;
use App\Parser\Support\Html;
use DOMElement;

class SudrfCourtDirectoryService
{
    private const DISTRICT_COURT_TYPE = 'RS';

    public function __construct(private readonly SudrfDirectoryHttpClient $httpClient) {}

    /** @return array<int, Court> */
    public function syncRegion(int $regionId, ?string $regionName = null, ?string $city = null, bool $dryRun = false): array
    {
        $region = Region::query()->where('sudrf_region_id', $regionId)->first();
        $storedRegionName = $regionName ?? $region?->name;
        $html = $this->fetchRegionHtml($regionId);
        $entries = $this->parseCourts($html);
        $courts = [];

        foreach ($entries as $entry) {
            if ($dryRun) {
                $court = new Court([
                    'region_id' => $region?->id,
                    'name' => $entry['name'],
                    'region' => $storedRegionName,
                    'city' => $city,
                    'court_level' => 'district',
                    'court_type' => 'municipal',
                    'source_type' => 'sudrf',
                    'base_url' => $entry['base_url'],
                    'layout_type' => 'sudrf_mobile',
                    'status' => 'active',
                ]);
            } else {
                $court = Court::query()->firstOrNew(['base_url' => $entry['base_url']]);
                $isNewCourt = ! $court->exists;
                $attributes = [
                    'name' => $entry['name'],
                    'court_level' => 'district',
                    'court_type' => 'municipal',
                    'source_type' => 'sudrf',
                    'layout_type' => 'sudrf_mobile',
                    'status' => 'active',
                ];

                if ($region instanceof Region) {
                    $attributes['region_id'] = $region->id;
                }

                if ($storedRegionName !== null) {
                    $attributes['region'] = $storedRegionName;
                }

                if ($city !== null) {
                    $attributes['city'] = $city;
                }

                $court->fill($attributes);

                if ($isNewCourt) {
                    $court->forceFill([
                        'is_enabled' => true,
                        'min_request_interval_ms' => 10000,
                        'max_parallel_requests' => 1,
                        'timeout_ms' => 30000,
                        'retry_count' => 2,
                        'backoff_multiplier' => 1.8,
                        'crawl_priority' => 100,
                    ]);
                }

                $court->save();
            }

            $courts[] = $court;
        }

        if (! $dryRun && $region instanceof Region) {
            $region->forceFill([
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        }

        return $courts;
    }

    protected function fetchRegionHtml(int $regionId): string
    {
        return $this->httpClient->get('https://sudrf.ru/index.php', [
            'id' => 300,
            'act' => 'go_search',
            'searchtype' => 'fs',
            'court_name' => '',
            'court_subj' => $regionId,
            'court_type' => self::DISTRICT_COURT_TYPE,
            'court_okrug' => 0,
            'vcourt_okrug' => 0,
        ]);
    }

    /** @return array<int, array{name: string, base_url: string}> */
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
}
