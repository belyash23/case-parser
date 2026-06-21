<?php

namespace App\Console\Commands;

use App\Parser\Services\DatasetExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DatasetExportCommand extends Command
{
    protected $signature = 'dataset:export {--from= : Start date YYYY-MM-DD} {--to= : End date YYYY-MM-DD} {--format=csv : csv or jsonl} {--path= : Storage path, default exports/dataset-{from}-{to}.{format}}';

    protected $description = 'Export parsed court cases dataset for ML experiments.';

    public function handle(DatasetExportService $exportService): int
    {
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');
        $format = strtolower((string) $this->option('format'));

        if ($from === null || $to === null) {
            $this->error('Both --from and --to are required, format YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (! in_array($format, ['csv', 'jsonl'], true)) {
            $this->error('--format must be csv or jsonl.');

            return self::FAILURE;
        }

        $pathOption = $this->option('path');
        $path = $exportService->export($from, $to, $format, is_string($pathOption) && $pathOption !== '' ? $pathOption : null);
        $this->info('Dataset exported to '.$path);

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
    }
}
