<?php

namespace App\Parser\Services;

use App\Models\Parser\AvailabilityCheck;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

class AvailabilityExportService
{
    public function __construct(private readonly StreamingExportWriter $writer) {}

    public function export(CarbonImmutable $from, CarbonImmutable $to, string $format, ?string $path = null): string
    {
        $format = strtolower($format);
        $path ??= 'exports/availability-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;

        return $this->writer->write(
            $path,
            $format,
            function (Closure $writeRow) use ($from, $to): void {
                AvailabilityCheck::query()
                    ->select([
                        'id',
                        'court_id',
                        'availability_incident_id',
                        'source',
                        'endpoint_type',
                        'url',
                        'checked_at',
                        'outcome',
                        'http_status',
                        'duration_ms',
                        'response_size_bytes',
                        'retry_after_seconds',
                        'error_type',
                        'error_message',
                        'probe_node',
                    ])
                    ->with('court:id,name')
                    ->whereBetween('checked_at', [$from->startOfDay(), $to->endOfDay()])
                    ->chunkById(500, function (Collection $checks) use ($writeRow): void {
                        foreach ($checks as $check) {
                            $writeRow($this->row($check));
                        }
                    });
            },
        );
    }

    /** @return array<string, mixed> */
    private function row(AvailabilityCheck $check): array
    {
        return [
            'check_id' => $check->id,
            'court_id' => $check->court_id,
            'court_name' => $check->court?->name,
            'checked_at_utc' => $check->checked_at?->utc()->toIso8601String(),
            'checked_at_local' => $check->checked_at?->toIso8601String(),
            'source' => $check->source,
            'endpoint_type' => $check->endpoint_type,
            'url' => $check->url,
            'outcome' => $check->outcome,
            'http_status' => $check->http_status,
            'duration_ms' => $check->duration_ms,
            'response_size_bytes' => $check->response_size_bytes,
            'retry_after_seconds' => $check->retry_after_seconds,
            'error_type' => $check->error_type,
            'error_message' => $check->error_message,
            'incident_id' => $check->availability_incident_id,
            'probe_node' => $check->probe_node,
        ];
    }
}
