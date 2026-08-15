<?php

namespace App\Parser\Services;

use App\Models\Parser\CaseInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class DatasetExportService
{
    public function export(CarbonImmutable $from, CarbonImmutable $to, string $format, ?string $path = null, bool $includeSourceUrl = false): string
    {
        $format = strtolower($format);
        $path ??= 'exports/dataset-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;
        $rows = $this->rows($from, $to, $includeSourceUrl);
        $content = $format === 'jsonl' ? $this->toJsonl($rows) : $this->toCsv($rows);

        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(CarbonImmutable $from, CarbonImmutable $to, bool $includeSourceUrl): array
    {
        return CaseInstance::query()
            ->with(['court', 'courtCase', 'events', 'parties'])
            ->whereHas('courtCase', fn ($query) => $query
                ->where('is_training_candidate', true)
                ->where('dispute_status', 'resolved')
                ->whereNotIn('chain_status', ['transfer_pending', 'merge_pending'])
                ->whereDate('received_date', '>=', $from->toDateString())
                ->whereDate('final_observed_date', '<=', $to->toDateString()))
            ->where('dispute_status_normalized', 'resolved')
            ->whereNotIn('result_normalized', ['transferred_by_jurisdiction', 'joined_to_another_case'])
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->orderBy('id')
            ->get()
            ->map(function (CaseInstance $instance) use ($includeSourceUrl): array {
                $events = $instance->events->sortBy('event_order')->values();
                $parties = $instance->parties;
                $logicalStartedAt = $instance->courtCase?->received_date ?? $instance->started_at;
                $logicalCompletedAt = $instance->courtCase?->final_observed_date ?? $instance->completed_at;
                $duration = $logicalStartedAt && $logicalCompletedAt
                    ? $logicalStartedAt->diffInDays($logicalCompletedAt)
                    : null;
                $row = [
                    'case_id' => $instance->case_id,
                    'case_instance_id' => $instance->id,
                    'court_id' => $instance->court_id,
                    'region' => $instance->court?->region,
                    'chain_status' => $instance->courtCase?->chain_status,
                    'case_type' => $instance->case_type,
                    'source_case_type_id' => $instance->source_case_type_id,
                    'instance_level' => $instance->instance_level,
                    'court_instance_status' => $instance->court_instance_status_normalized,
                    'dispute_status' => $instance->dispute_status_normalized,
                    'disposition_type' => $instance->disposition_type,
                    'category_normalized' => $instance->category_normalized,
                    'category_level_1' => $instance->category_level_1,
                    'category_level_2' => $instance->category_level_2,
                    'category_level_3' => $instance->category_level_3,
                    'category_level_4' => $instance->category_level_4,
                    'category_leaf' => $instance->category_leaf,
                    'received_date' => $logicalStartedAt?->toDateString(),
                    'completed_at' => $logicalCompletedAt?->toDateString(),
                    'result_normalized' => $instance->result_normalized,
                    'duration_days' => $duration,
                    'plaintiffs_count' => $parties->where('role', 'plaintiff')->count(),
                    'defendants_count' => $parties->where('role', 'defendant')->count(),
                    'applicants_count' => $parties->where('role', 'applicant')->count(),
                    'representatives_count' => $parties->where('role', 'representative')->count(),
                    'unknown_role_parties_count' => $parties->where('role', 'unknown')->count(),
                    'claimant_group_parties_count' => $parties->where('role_group', 'claimant')->count(),
                    'respondent_group_parties_count' => $parties->where('role_group', 'respondent')->count(),
                    'public_participant_group_parties_count' => $parties->where('role_group', 'public_participant')->count(),
                    'third_parties_count' => $parties->whereIn('role_group', ['third_party', 'independent_party', 'dependent_party'])->count(),
                    'hidden_parties_count' => $parties->where('is_hidden', true)->count(),
                    'has_hidden_party' => $parties->contains('is_hidden', true),
                    'has_unknown_role_party' => $parties->contains('role', 'unknown'),
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

                if ($includeSourceUrl) {
                    $row['source_url'] = $instance->source_url;
                }

                return $row;
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
            ->implode("\n")."\n";
    }
}