<?php

namespace App\Parser\Services;

use App\Models\Parser\AvailabilityCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class AvailabilityExportService
{
    public function export(CarbonImmutable $from, CarbonImmutable $to, string $format, ?string $path = null): string
    {
        $format = strtolower($format);
        $path ??= 'exports/availability-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;
        $rows = $this->rows($from, $to);
        $content = $format === 'jsonl' ? $this->toJsonl($rows) : $this->toCsv($rows);

        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return AvailabilityCheck::query()
            ->with('court:id,name')
            ->whereBetween('checked_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('checked_at')
            ->get()
            ->map(fn (AvailabilityCheck $check): array => [
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
            ])
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
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function toJsonl(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        return collect($rows)
            ->map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n")."\n";
    }
}
