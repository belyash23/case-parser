<?php

namespace App\Parser\Services;

use App\Models\Parser\Region;
use App\Parser\Exceptions\SourceCircuitOpenException;
use Throwable;

class SudrfDirectorySyncService
{
    public function __construct(
        private readonly SudrfRegionDirectoryService $regionDirectory,
        private readonly SudrfCourtDirectoryService $courtDirectory,
    ) {}

    /**
     * @param  array<int, int>  $regionIds
     * @return array{regions:int, courts:int, failures:int}
     */
    public function sync(array $regionIds = [], bool $refreshRegions = true, bool $dryRun = false): array
    {
        $regions = $refreshRegions
            ? $this->regionDirectory->sync($dryRun)
            : Region::query()->enabled()->orderBy('sudrf_region_id')->get()->all();

        if ($refreshRegions && ! $dryRun) {
            $regions = Region::query()->enabled()->orderBy('sudrf_region_id')->get()->all();
        }

        if ($regionIds !== []) {
            $regions = array_values(array_filter(
                $regions,
                fn (Region $region): bool => in_array((int) $region->sudrf_region_id, $regionIds, true),
            ));
        }

        $courtCount = 0;
        $failureCount = 0;

        foreach ($regions as $region) {
            try {
                $courts = $this->courtDirectory->syncRegion(
                    (int) $region->sudrf_region_id,
                    $region->name,
                    dryRun: $dryRun,
                );
                $courtCount += count($courts);
            } catch (SourceCircuitOpenException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failureCount++;

                if (! $dryRun && $region->exists) {
                    $region->forceFill([
                        'sync_status' => 'failed',
                        'last_error' => $exception->getMessage(),
                    ])->save();
                }
            }
        }

        return [
            'regions' => count($regions),
            'courts' => $courtCount,
            'failures' => $failureCount,
        ];
    }
}
