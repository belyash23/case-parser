<?php

namespace App\Jobs\Admin;

use App\Enums\Admin\ReportStatus;
use App\Enums\Admin\ReportType;
use App\Models\Admin\AdminReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateAdminReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public array $backoff = [60];

    public function __construct(public int $reportId)
    {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return (string) $this->reportId;
    }

    public function handle(): void
    {
        $report = AdminReport::query()->findOrFail($this->reportId);
        $report->update([
            'status' => ReportStatus::Running,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $path = 'admin-reports/'.$report->id.'/'.$this->filename($report);
        $arguments = $this->arguments($report, $path);

        if (Artisan::call($this->command($report->type), $arguments) !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'Report command failed.');
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Report command completed without creating a file.');
        }

        $report->update([
            'status' => ReportStatus::Ready,
            'path' => $path,
            'size_bytes' => Storage::disk('local')->size($path),
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AdminReport::query()->whereKey($this->reportId)->update([
            'status' => ReportStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Unknown report generation error.',
            'finished_at' => now(),
        ]);
    }

    private function command(ReportType $type): string
    {
        return match ($type) {
            ReportType::Availability => 'monitor:export',
            ReportType::Dataset => 'dataset:export',
            ReportType::CaseInspection => 'parser:inspect-cases',
        };
    }

    /** @return array<string, mixed> */
    private function arguments(AdminReport $report, string $path): array
    {
        $filters = $report->filters_json ?? [];
        $arguments = ['--path' => $path];

        $optionKeys = match ($report->type) {
            ReportType::Availability => ['from', 'to', 'format'],
            ReportType::Dataset => ['from', 'to', 'format'],
            ReportType::CaseInspection => ['from', 'to', 'court_id', 'limit'],
        };

        foreach ($optionKeys as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $arguments['--'.$key] = $filters[$key];
            }
        }

        if ($report->type === ReportType::CaseInspection && ! empty($filters['ids'])) {
            $arguments['--id'] = $filters['ids'];
        }

        if ($report->type === ReportType::Dataset && ! empty($filters['include_source_url'])) {
            $arguments['--include-source-url'] = true;
        }

        return $arguments;
    }

    private function filename(AdminReport $report): string
    {
        $extension = $report->type === ReportType::CaseInspection ? 'json' : $report->format;

        return $report->type->value.'-'.$report->created_at->format('Ymd-His').'.'.$extension;
    }
}
