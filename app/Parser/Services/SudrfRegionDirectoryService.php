<?php

namespace App\Parser\Services;

use App\Models\Parser\Region;
use App\Parser\Support\Html;
use DOMElement;

class SudrfRegionDirectoryService
{
    private const DIRECTORY_URL = 'https://sudrf.ru/index.php';

    public function __construct(private readonly SudrfDirectoryHttpClient $httpClient) {}

    /** @return array<int, Region> */
    public function sync(bool $dryRun = false): array
    {
        $html = $this->httpClient->get(self::DIRECTORY_URL, ['id' => 300]);
        $entries = $this->parseRegions($html);
        $regions = [];

        foreach ($entries as $entry) {
            if ($dryRun) {
                $region = new Region([
                    'sudrf_region_id' => $entry['sudrf_region_id'],
                    'name' => $entry['name'],
                    'sync_status' => 'parsed',
                ]);
            } else {
                $region = Region::query()->updateOrCreate(
                    ['sudrf_region_id' => $entry['sudrf_region_id']],
                    [
                        'source_type' => 'sudrf',
                        'name' => $entry['name'],
                        'sync_status' => 'synced',
                        'last_synced_at' => now(),
                        'last_error' => null,
                    ],
                );
            }

            $regions[] = $region;
        }

        return $regions;
    }

    /** @return array<int, array{sudrf_region_id:int, name:string}> */
    private function parseRegions(string $html): array
    {
        $xpath = Html::xpath($html);
        $regions = [];

        foreach ($xpath->query('//select[@name="court_subj"]//option[@value]') as $option) {
            if (! $option instanceof DOMElement) {
                continue;
            }

            $externalId = $option->getAttribute('value');
            $name = Html::text($option);

            if (! ctype_digit($externalId) || (int) $externalId <= 0 || $name === '') {
                continue;
            }

            $regions[(int) $externalId] = [
                'sudrf_region_id' => (int) $externalId,
                'name' => $name,
            ];
        }

        ksort($regions);

        return array_values($regions);
    }
}
