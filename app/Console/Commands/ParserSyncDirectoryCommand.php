<?php

namespace App\Console\Commands;

use App\Parser\Services\SudrfDirectorySyncService;
use Illuminate\Console\Command;

class ParserSyncDirectoryCommand extends Command
{
    protected $signature = 'parser:sync-directory
        {--region=* : Limit synchronization to SUDRF region IDs}
        {--skip-region-refresh : Use regions already stored in the database}
        {--dry-run : Parse directories without writing to the database}';

    protected $description = 'Synchronize enabled SUDRF regions and their court directory entries.';

    public function handle(SudrfDirectorySyncService $directorySync): int
    {
        $rawRegionIds = $this->option('region');
        $hasInvalidRegionId = collect($rawRegionIds)
            ->contains(fn (mixed $regionId): bool => ! ctype_digit((string) $regionId) || (int) $regionId < 1);

        if ($hasInvalidRegionId) {
            $this->error('Each --region value must be a positive integer SUDRF region ID.');

            return self::FAILURE;
        }

        $regionIds = collect($rawRegionIds)
            ->map(fn (mixed $regionId): int => (int) $regionId)
            ->unique()
            ->values()
            ->all();

        $result = $directorySync->sync(
            regionIds: $regionIds,
            refreshRegions: ! (bool) $this->option('skip-region-refresh'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            'Directory sync finished: %d regions, %d courts, %d region failures.',
            $result['regions'],
            $result['courts'],
            $result['failures'],
        ));

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
