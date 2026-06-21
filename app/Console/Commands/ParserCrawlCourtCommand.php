<?php

namespace App\Console\Commands;

use App\Models\Parser\Court;
use App\Models\Parser\ParserRun;
use App\Parser\Services\CourtCrawler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ParserCrawlCourtCommand extends Command
{
    protected $signature = 'parser:crawl-court {court_id : Court ID} {--from= : Start date YYYY-MM-DD} {--to= : End date YYYY-MM-DD} {--include-out-of-window : Persist parsed cases outside the observation window}';

    protected $description = 'Crawl a sudrf.ru court hearing calendar for a date range and parse civil first-instance case cards.';

    public function handle(CourtCrawler $crawler): int
    {
        $court = Court::query()->find($this->argument('court_id'));
        if ($court === null) {
            $this->error('Court not found.');

            return self::FAILURE;
        }

        $from = $this->dateOption('from');
        $to = $this->dateOption('to');
        if ($from === null || $to === null) {
            $this->error('Both --from and --to are required, format YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($from->gt($to)) {
            $this->error('--from must be less than or equal to --to.');

            return self::FAILURE;
        }

        $run = ParserRun::query()->create([
            'run_type' => 'single_court',
            'status' => 'running',
            'started_at' => now(),
            'parser_version' => config('parser.version'),
            'settings_json' => [
                'court_id' => $court->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'discovery' => 'hearing_calendar',
                'persist_out_of_window' => (bool) $this->option('include-out-of-window'),
            ],
        ]);

        try {
            $crawler->crawlCourt($court, $from, $to, $run, (bool) $this->option('include-out-of-window'));
            $run->refresh()->markCompleted();
            $run->refresh();

            $this->info(sprintf(
                'Run %d completed: %d requests, %d calendar links, %d new cases, %d updated cases, %d training candidates, %d out-of-window.',
                $run->id,
                $run->total_requests,
                $run->calendar_case_links_count,
                $run->new_cases_count,
                $run->updated_cases_count,
                $run->training_candidate_cases_count,
                $run->out_of_window_cases_count,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $run->refresh()->markFailed();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
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
