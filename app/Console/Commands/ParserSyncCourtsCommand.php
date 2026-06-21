<?php

namespace App\Console\Commands;

use App\Parser\Services\SudrfCourtDirectoryService;
use Illuminate\Console\Command;

class ParserSyncCourtsCommand extends Command
{
    protected $signature = 'parser:sync-courts {region_id=18 : SUDRF court_subj region identifier} {--region=Udmurt Republic : Region name to store on courts} {--city= : Optional city label to store on synced courts} {--dry-run : Parse and print courts without writing to the database}';

    protected $description = 'Sync court directory entries from sudrf.ru by region.';

    public function handle(SudrfCourtDirectoryService $directoryService): int
    {
        $regionId = (int) $this->argument('region_id');
        $region = $this->option('region');
        $city = $this->option('city');
        $dryRun = (bool) $this->option('dry-run');

        $courts = $directoryService->syncRegion(
            regionId: $regionId,
            regionName: is_string($region) && $region !== '' ? $region : null,
            city: is_string($city) && $city !== '' ? $city : null,
            dryRun: $dryRun,
        );

        foreach ($courts as $court) {
            $this->line($court->base_url.' '.$court->name);
        }

        $this->info(($dryRun ? 'Parsed' : 'Synced').' '.count($courts).' courts.');

        return self::SUCCESS;
    }
}
