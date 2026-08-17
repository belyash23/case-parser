<?php

namespace App\Parser\Services;

use App\Models\Parser\CaseInstance;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DatasetExportService
{
    public function __construct(private readonly StreamingExportWriter $writer) {}

    public function export(CarbonImmutable $from, CarbonImmutable $to, string $format, ?string $path = null, bool $includeSourceUrl = false): string
    {
        $format = strtolower($format);
        $path ??= 'exports/dataset-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;

        return $this->writer->write(
            $path,
            $format,
            function (Closure $writeRow) use ($from, $to, $includeSourceUrl): void {
                $this->query($from, $to, $includeSourceUrl)
                    ->chunkById(250, function (Collection $instances) use ($writeRow, $includeSourceUrl): void {
                        foreach ($instances as $instance) {
                            $row = $this->row($instance, $includeSourceUrl);

                            if ($row !== null) {
                                $writeRow($row);
                            }
                        }
                    });
            },
        );
    }

    /** @return Builder<CaseInstance> */
    private function query(CarbonImmutable $from, CarbonImmutable $to, bool $includeSourceUrl): Builder
    {
        $columns = [
            'id',
            'case_id',
            'court_id',
            'case_type',
            'source_case_type_id',
            'instance_level',
            'category_normalized',
            'category_level_1',
            'category_level_2',
            'category_level_3',
            'category_level_4',
            'category_leaf',
            'started_at',
            'completed_at',
        ];

        if ($includeSourceUrl) {
            $columns[] = 'source_url';
        }

        return CaseInstance::query()
            ->select($columns)
            ->with([
                'court:id,region',
                'courtCase:id,received_date,final_observed_date',
                'parties:id,case_instance_id,role,role_group,party_type,is_hidden',
            ])
            ->whereHas('courtCase', fn (Builder $query): Builder => $query
                ->where('is_training_candidate', true)
                ->where('dispute_status', 'resolved')
                ->whereNotIn('chain_status', ['transfer_pending', 'merge_pending'])
                ->whereDate('received_date', '>=', $from->toDateString())
                ->whereDate('final_observed_date', '<=', $to->toDateString()))
            ->where('dispute_status_normalized', 'resolved')
            ->whereNotIn('result_normalized', ['transferred_by_jurisdiction', 'joined_to_another_case'])
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at');
    }

    /** @return array<string, mixed>|null */
    private function row(CaseInstance $instance, bool $includeSourceUrl): ?array
    {
        $parties = $instance->parties;
        $receivedAt = $instance->courtCase?->received_date ?? $instance->started_at;
        $completedAt = $instance->courtCase?->final_observed_date ?? $instance->completed_at;

        if ($receivedAt === null || $completedAt === null) {
            return null;
        }

        $duration = (int) $receivedAt->diffInDays($completedAt, false);

        if ($duration < 0) {
            return null;
        }

        $row = [
            'case_id' => $instance->case_id,
            'case_instance_id' => $instance->id,
            'court_id' => $instance->court_id,
            'region' => $instance->court?->region,
            'case_type' => $instance->case_type,
            'source_case_type_id' => $instance->source_case_type_id,
            'instance_level' => $instance->instance_level,
            'category_normalized' => $instance->category_normalized,
            'category_level_1' => $instance->category_level_1,
            'category_level_2' => $instance->category_level_2,
            'category_level_3' => $instance->category_level_3,
            'category_level_4' => $instance->category_level_4,
            'category_leaf' => $instance->category_leaf,
            'feature_cutoff_date' => $receivedAt->toDateString(),
            'received_year' => $receivedAt->year,
            'received_month' => $receivedAt->month,
            'received_day_of_week' => $receivedAt->dayOfWeekIso,
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
            'target_duration_days' => $duration,
        ];

        if ($includeSourceUrl) {
            $row['source_url'] = $instance->source_url;
        }

        return $row;
    }
}
