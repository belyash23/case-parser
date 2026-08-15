<?php

namespace App\Console\Commands;

use App\Parser\Services\AvailabilityExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AvailabilityExportCommand extends Command
{
    protected $signature = 'monitor:export
        {--from= : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD}
        {--format=csv : csv or jsonl}
        {--path= : Storage path relative to the local disk}';

    protected $description = 'Export recorded SUDRF availability checks.';

    public function handle(AvailabilityExportService $exporter): int
    {
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');
        $format = strtolower((string) $this->option('format'));

        if ($from === null || $to === null) {
            $this->error('Both --from and --to are required in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        if (! in_array($format, ['csv', 'jsonl'], true)) {
            $this->error('--format must be csv or jsonl.');

            return self::FAILURE;
        }

        $pathOption = $this->option('path');
        $path = $exporter->export(
            from: $from,
            to: $to,
            format: $format,
            path: is_string($pathOption) && $pathOption !== '' ? $pathOption : null,
        );
        $this->info('Availability checks exported to '.$path);

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
