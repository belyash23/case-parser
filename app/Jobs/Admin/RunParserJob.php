<?php

namespace App\Jobs\Admin;

use App\Admin\Services\ParserSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunParserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(public string $mode, public array $arguments = [])
    {
        $this->onQueue('parser');
    }

    public function uniqueId(): string
    {
        return 'sudrf-parser';
    }

    public function handle(ParserSettings $settings): void
    {
        $sliceSeconds = max(10, min(110, $settings->current()->regular_slice_seconds));
        $command = $this->mode === 'initial' ? 'parser:crawl-initial' : 'parser:crawl-regular';
        $arguments = [...$this->arguments, '--time-limit' => $sliceSeconds];

        if (Artisan::call($command, $arguments) !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: "Command {$command} failed.");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued parser run failed.', [
            'mode' => $this->mode,
            'campaign_id' => $this->arguments['--campaign'] ?? null,
            'exception' => $exception,
        ]);
    }
}
