<?php

namespace App\Console\Commands;

use App\Enums\Parser\CrawlWorkStatus;
use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserError;
use App\Models\Parser\ParserRun;
use App\Models\Parser\RawPage;
use App\Models\Parser\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ParserPruneOperationalDataCommand extends Command
{
    protected $signature = 'parser:prune-operational-data {--dry-run : Show eligible records without deleting them}';

    protected $description = 'Prune expired parser logs, checks, work items, runs, and raw HTML files.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'availability_checks' => $this->pruneAvailabilityChecks($dryRun),
            'request_logs' => $this->pruneRequestLogs($dryRun),
            'parser_errors' => $this->pruneParserErrors($dryRun),
            'crawl_work_items' => $this->pruneWorkItems($dryRun),
            'parser_runs' => $this->pruneParserRuns($dryRun),
            'raw_page_files' => $this->pruneRawPageFiles($dryRun),
            'raw_page_records' => $this->pruneRawPageRecords($dryRun),
        ];

        $this->table(
            ['Data set', $dryRun ? 'Eligible' : 'Deleted'],
            collect($counts)->map(fn (int $count, string $name): array => [$name, $count])->values()->all(),
        );

        return self::SUCCESS;
    }

    private function pruneAvailabilityChecks(bool $dryRun): int
    {
        $query = AvailabilityCheck::query()->where(
            'checked_at',
            '<',
            now()->subDays($this->retentionDays('availability_check_retention_days', 365)),
        );

        return $this->pruneById($query, AvailabilityCheck::class, $dryRun);
    }

    private function pruneRequestLogs(bool $dryRun): int
    {
        $query = RequestLog::query()->where(
            'created_at',
            '<',
            now()->subDays($this->retentionDays('request_log_retention_days', 90)),
        );

        return $this->pruneById($query, RequestLog::class, $dryRun);
    }

    private function pruneParserErrors(bool $dryRun): int
    {
        $query = ParserError::query()->where(
            'occurred_at',
            '<',
            now()->subDays($this->retentionDays('parser_error_retention_days', 180)),
        );

        return $this->pruneById($query, ParserError::class, $dryRun);
    }

    private function pruneWorkItems(bool $dryRun): int
    {
        $query = CrawlWorkItem::query()
            ->whereIn('status', [CrawlWorkStatus::Completed, CrawlWorkStatus::Cancelled])
            ->where('finished_at', '<', now()->subDays($this->retentionDays('work_item_retention_days', 180)));

        return $this->pruneById($query, CrawlWorkItem::class, $dryRun);
    }

    private function pruneParserRuns(bool $dryRun): int
    {
        $query = ParserRun::query()
            ->whereIn('status', ['completed', 'failed', 'paused', 'interrupted'])
            ->where('finished_at', '<', now()->subDays($this->retentionDays('parser_run_retention_days', 365)));

        return $this->pruneById($query, ParserRun::class, $dryRun);
    }

    private function pruneRawPageFiles(bool $dryRun): int
    {
        $query = RawPage::query()
            ->whereNotNull('sanitized_html_path')
            ->where('fetched_at', '<', now()->subDays($this->retentionDays('raw_page_file_retention_days', 90)));

        if ($dryRun) {
            return (clone $query)->count();
        }

        $count = 0;
        $query->select(['id', 'sanitized_html_path'])->chunkById(200, function (Collection $pages) use (&$count): void {
            foreach ($pages as $page) {
                if ($page->sanitized_html_path !== null) {
                    Storage::disk('local')->delete($page->sanitized_html_path);
                }
            }

            $ids = $pages->modelKeys();
            $count += RawPage::query()->whereKey($ids)->update(['sanitized_html_path' => null]);
        });

        return $count;
    }

    private function pruneRawPageRecords(bool $dryRun): int
    {
        $query = RawPage::query()
            ->doesntHave('caseInstances')
            ->whereNull('sanitized_html_path')
            ->where('fetched_at', '<', now()->subDays($this->retentionDays('raw_page_record_retention_days', 180)));

        return $this->pruneById($query, RawPage::class, $dryRun);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<TModel>  $modelClass
     */
    private function pruneById(Builder $query, string $modelClass, bool $dryRun): int
    {
        if ($dryRun) {
            return (clone $query)->count();
        }

        $count = 0;
        $query->select('id')->chunkById(500, function (Collection $models) use ($modelClass, &$count): void {
            $count += $modelClass::query()->whereKey($models->modelKeys())->delete();
        });

        return $count;
    }

    private function retentionDays(string $key, int $default): int
    {
        return max(1, (int) config('parser.operations.'.$key, $default));
    }
}
