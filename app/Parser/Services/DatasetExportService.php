<?php

namespace App\Parser\Services;

use App\Models\Parser\CaseInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class DatasetExportService
{
    public function export(CarbonImmutable $from, CarbonImmutable $to, string $format, ?string $path = null): string
    {
        $format = strtolower($format);
        $path ??= 'exports/dataset-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;
        $rows = $this->rows($from, $to);
        $content = $format === 'jsonl' ? $this->toJsonl($rows) : $this->toCsv($rows);

        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return CaseInstance::query()
            ->with(['court', 'courtCase', 'events', 'parties'])
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->whereDate('started_at', '>=', $from->toDateString())
            ->whereDate('completed_at', '<=', $to->toDateString())
            ->orderBy('id')
            ->get()
            ->map(function (CaseInstance $instance): array {
                $events = $instance->events->sortBy('event_order')->values();
                $parties = $instance->parties;
                $duration = $instance->started_at && $instance->completed_at
                    ? $instance->started_at->diffInDays($instance->completed_at)
                    : null;

                return [
                    'case_id' => $instance->case_id,
                    'case_instance_id' => $instance->id,
                    'court_id' => $instance->court_id,
                    'region' => $instance->court?->region,
                    'instance_level' => $instance->instance_level,
                    'category_normalized' => $instance->category_normalized,
                    'received_date' => $instance->started_at?->toDateString(),
                    'completed_at' => $instance->completed_at?->toDateString(),
                    'result_normalized' => $instance->result_normalized,
                    'duration_days' => $duration,
                    'plaintiffs_count' => $parties->where('role', 'plaintiff')->count(),
                    'defendants_count' => $parties->where('role', 'defendant')->count(),
                    'third_parties_count' => $parties->where('role', 'third_party')->count(),
                    'has_individual_party' => $parties->contains('party_type', 'individual'),
                    'has_legal_entity_party' => $parties->contains('party_type', 'legal_entity'),
                    'has_government_party' => $parties->contains('party_type', 'government'),
                    'events_count' => $events->count(),
                    'hearings_count' => $events->whereIn('event_type_normalized', ['hearing_scheduled', 'hearing_held'])->count(),
                    'postponements_count' => $events->where('event_type_normalized', 'hearing_postponed')->count(),
                    'has_suspension' => $events->contains('event_type_normalized', 'proceeding_suspended'),
                    'has_expertise' => $events->contains('event_type_normalized', 'expertise_ordered'),
                    'has_appeal' => (bool) $instance->courtCase?->has_appeal,
                    'has_cassation' => (bool) $instance->courtCase?->has_cassation,
                    'event_sequence' => $events->pluck('event_type_normalized')->implode('>'),
                ];
            })
            ->all();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value, $row));
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toJsonl(array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode('
').'
';
    }
}
