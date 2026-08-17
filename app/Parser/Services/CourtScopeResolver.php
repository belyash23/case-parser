<?php

namespace App\Parser\Services;

use App\Models\Parser\Court;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class CourtScopeResolver
{
    /**
     * @param  array<int, int>  $courtIds
     * @param  array<int, int>  $regionIds
     * @return Collection<int, Court>
     */
    public function resolve(array $courtIds = [], array $regionIds = []): Collection
    {
        $courts = Court::query()
            ->where('source_type', 'sudrf')
            ->where('is_enabled', true)
            ->when($courtIds !== [], fn ($query) => $query->whereIn('id', $courtIds))
            ->when($regionIds !== [], fn ($query) => $query->whereHas('region', fn ($regionQuery) => $regionQuery->whereIn('sudrf_region_id', $regionIds)))
            ->with('crawlState')
            ->orderBy('crawl_priority')
            ->orderBy('id')
            ->get();

        if ($courts->isEmpty()) {
            throw new InvalidArgumentException('No enabled SUDRF courts matched the requested scope.');
        }

        if ($courtIds !== []) {
            $selectedIds = $courts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $missingCourtIds = array_values(array_diff($courtIds, $selectedIds));

            if ($missingCourtIds !== []) {
                throw new InvalidArgumentException('Some selected courts are missing, disabled, or outside the region filter: '.implode(', ', $missingCourtIds));
            }
        }

        return $courts;
    }

    /** @param array<int, int> $courtIds */
    public function regionIdsForCourts(array $courtIds): array
    {
        if ($courtIds === []) {
            return [];
        }

        return Court::query()
            ->whereIn('id', $courtIds)
            ->whereNotNull('region_id')
            ->with('region:id,sudrf_region_id')
            ->get(['id', 'region_id'])
            ->pluck('region.sudrf_region_id')
            ->filter()
            ->map(fn (mixed $regionId): int => (int) $regionId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $courtIds
     * @param  array<int, int>  $regionIds
     */
    public function scopeKey(array $courtIds, array $regionIds): string
    {
        sort($courtIds);
        sort($regionIds);

        return hash('sha256', json_encode([
            'courts' => $courtIds,
            'regions' => $regionIds,
        ], JSON_THROW_ON_ERROR));
    }
}
