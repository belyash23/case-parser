<?php

namespace App\Console\Commands;

use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\Court;
use App\Parser\Services\AvailabilityMonitorService;
use Illuminate\Console\Command;

class SudrfMonitorCommand extends Command
{
    protected $signature = 'monitor:sudrf
        {--court=* : Limit monitoring to the specified court IDs}
        {--force : Run even when monitoring is disabled in configuration}';

    protected $description = 'Record SUDRF availability, reusing recent parser requests when possible.';

    public function handle(AvailabilityMonitorService $monitor): int
    {
        if (! (bool) config('monitoring.sudrf.enabled') && ! $this->option('force')) {
            $this->info('SUDRF monitoring is disabled. Set SUDRF_MONITORING_ENABLED=true or pass --force.');

            return self::SUCCESS;
        }

        $courtIds = collect($this->option('court'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $courts = Court::query()
            ->where('source_type', 'sudrf')
            ->where('is_enabled', true)
            ->when($courtIds !== [], fn ($query) => $query->whereIn('id', $courtIds))
            ->orderBy('crawl_priority')
            ->orderBy('id')
            ->get();

        if ($courts->isEmpty()) {
            $this->warn('No enabled SUDRF courts matched the selection.');

            return self::SUCCESS;
        }

        $parserChecks = $monitor->reuseRecentParserActivity($courts->pluck('id')->all());

        if ($parserChecks->isNotEmpty()) {
            $this->info('Recent parser activity found; scheduled probes are skipped for all courts.');
            $parserChecks->each(fn ($check) => $this->renderCheck($check));

            return self::SUCCESS;
        }

        foreach ($courts as $court) {
            $this->renderCheck($monitor->check($court));
        }

        return self::SUCCESS;
    }

    private function renderCheck(AvailabilityCheck $check): void
    {
        $this->line(sprintf(
            '[%s] court=%d source=%s outcome=%s status=%s duration_ms=%s',
            $check->checked_at?->toIso8601String(),
            $check->court_id,
            $check->source,
            $check->outcome,
            $check->http_status ?? '-',
            $check->duration_ms ?? '-',
        ));
    }
}
