<?php

namespace App\Console\Commands;

use App\Parser\Services\SudrfRegionDirectoryService;
use Illuminate\Console\Command;

class ParserSyncRegionsCommand extends Command
{
    protected $signature = 'parser:sync-regions {--dry-run : Parse and print regions without writing to the database}';

    protected $description = 'Synchronize the SUDRF region directory.';

    public function handle(SudrfRegionDirectoryService $directoryService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $regions = $directoryService->sync($dryRun);

        foreach ($regions as $region) {
            $this->line($region->sudrf_region_id.' '.$region->name);
        }

        $this->info(($dryRun ? 'Parsed' : 'Synced').' '.count($regions).' regions.');

        return self::SUCCESS;
    }
}
