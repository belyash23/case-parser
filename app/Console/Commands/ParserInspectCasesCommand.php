<?php

namespace App\Console\Commands;

use App\Models\Parser\CaseInstance;
use App\Models\Parser\RequestLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ParserInspectCasesCommand extends Command
{
    protected $signature = 'parser:inspect-cases
        {--id=* : Specific case_instance id. Can be passed multiple times}
        {--court_id= : Filter by court id when ids are not passed}
        {--from= : Filter started_at from date YYYY-MM-DD when ids are not passed}
        {--to= : Filter started_at to date YYYY-MM-DD when ids are not passed}
        {--limit=10 : Number of latest case instances to export when ids are not passed}
        {--path= : Storage path, default exports/parser-inspection-{timestamp}.json}';

    protected $description = 'Export parsed case details for manual parser inspection.';

    public function handle(): int
    {
        $ids = $this->caseInstanceIds();
        $limit = $this->limitOption();
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if ($from === false || $to === false) {
            $this->error('--from and --to must use YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $instances = $this->queryInstances($ids, $limit, $from, $to)
            ->get()
            ->sortByDesc('id')
            ->values();

        $pathOption = $this->option('path');
        $path = is_string($pathOption) && $pathOption !== ''
            ? $pathOption
            : 'exports/parser-inspection-'.now()->format('Ymd-His').'.json';

        $payload = [
            'generated_at' => now()->toISOString(),
            'filters' => [
                'ids' => $ids,
                'court_id' => $this->option('court_id'),
                'from' => $from instanceof CarbonImmutable ? $from->toDateString() : null,
                'to' => $to instanceof CarbonImmutable ? $to->toDateString() : null,
                'limit' => $ids === [] ? $limit : null,
            ],
            'count' => $instances->count(),
            'cases' => $instances->map(fn (CaseInstance $instance): array => $this->serializeInstance($instance))->all(),
        ];

        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $absolutePath = Storage::disk('local')->path($path);
        $this->info('Inspection exported to '.$absolutePath);
        $this->table(
            ['case_instance_id', 'case_number', 'court_id', 'started_at', 'completed_at', 'source_url'],
            $instances->map(fn (CaseInstance $instance): array => [
                $instance->id,
                $instance->external_case_number,
                $instance->court_id,
                $instance->started_at?->toDateString(),
                $instance->completed_at?->toDateString(),
                $instance->source_url,
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function caseInstanceIds(): array
    {
        $ids = $this->option('id');

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function limitOption(): int
    {
        $limit = $this->option('limit');

        return max(1, min(100, is_numeric($limit) ? (int) $limit : 10));
    }

    private function dateOption(string $name): CarbonImmutable|false|null
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof CarbonImmutable ? $date->startOfDay() : false;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function queryInstances(array $ids, int $limit, CarbonImmutable|false|null $from, CarbonImmutable|false|null $to): mixed
    {
        $query = CaseInstance::query()
            ->with([
                'court',
                'courtCase.primaryCourt',
                'rawPage',
                'events' => fn ($query) => $query->orderBy('event_order')->orderBy('id'),
                'documents' => fn ($query) => $query->orderBy('document_date')->orderBy('id'),
                'parties' => fn ($query) => $query->orderBy('role')->orderBy('id'),
            ]);

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderByDesc('id');
        }

        $courtId = $this->option('court_id');
        if (is_numeric($courtId) && (int) $courtId > 0) {
            $query->where('court_id', (int) $courtId);
        }

        if ($from instanceof CarbonImmutable) {
            $query->whereDate('started_at', '>=', $from->toDateString());
        }

        if ($to instanceof CarbonImmutable) {
            $query->whereDate('started_at', '<=', $to->toDateString());
        }

        return $query->orderByDesc('id')->limit($limit);
    }

    /** @return array<string, mixed> */
    private function serializeInstance(CaseInstance $instance): array
    {
        return [
            'case_instance' => $instance->attributesToArray(),
            'court' => $instance->court?->attributesToArray(),
            'logical_case' => $instance->courtCase?->attributesToArray(),
            'raw_page' => $instance->rawPage?->attributesToArray(),
            'events' => $instance->events->map->attributesToArray()->all(),
            'parties' => $instance->parties->map->attributesToArray()->all(),
            'documents' => $instance->documents->map->attributesToArray()->all(),
            'request_logs' => $this->requestLogs($instance),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function requestLogs(CaseInstance $instance): array
    {
        return RequestLog::query()
            ->where('court_id', $instance->court_id)
            ->where('url_hash', $instance->source_url_hash)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map->attributesToArray()
            ->all();
    }
}
