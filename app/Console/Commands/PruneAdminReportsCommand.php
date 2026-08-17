<?php

namespace App\Console\Commands;

use App\Enums\Admin\ReportStatus;
use App\Models\Admin\AdminReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneAdminReportsCommand extends Command
{
    protected $signature = 'admin:reports-prune';

    protected $description = 'Delete expired admin report files.';

    public function handle(): int
    {
        $count = 0;

        AdminReport::query()
            ->where('expires_at', '<=', now())
            ->whereNot('status', ReportStatus::Expired)
            ->chunkById(100, function ($reports) use (&$count): void {
                foreach ($reports as $report) {
                    if ($report->path !== null) {
                        Storage::disk('local')->delete($report->path);
                    }

                    $report->update([
                        'status' => ReportStatus::Expired,
                        'path' => null,
                        'size_bytes' => null,
                    ]);
                    $count++;
                }
            });

        $this->info("Expired reports pruned: {$count}.");

        return self::SUCCESS;
    }
}
