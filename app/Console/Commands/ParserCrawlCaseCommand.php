<?php

namespace App\Console\Commands;

use App\Models\Parser\Court;
use App\Models\Parser\ParserRun;
use App\Parser\Services\CourtCrawler;
use Illuminate\Console\Command;
use Throwable;

class ParserCrawlCaseCommand extends Command
{
    protected $signature = 'parser:crawl-case {url : sudrf.ru case card URL} {--court_id= : Court ID, optional if URL starts with a known court base URL}';

    protected $description = 'Fetch and parse a single sudrf.ru case card.';

    public function handle(CourtCrawler $crawler): int
    {
        $url = (string) $this->argument('url');
        $court = $this->resolveCourt($url);

        if ($court === null) {
            $this->error('Court not found for this URL. Pass --court_id or seed Izhevsk courts.');

            return self::FAILURE;
        }

        $run = ParserRun::query()->create([
            'run_type' => 'single_case',
            'status' => 'running',
            'started_at' => now(),
            'parser_version' => config('parser.version'),
            'settings_json' => ['url' => $url, 'court_id' => $court->id],
        ]);

        try {
            $crawler->crawlCase($court, $url, $run, persistOutOfWindow: true);
            $run->refresh()->markCompleted();
            $run->refresh();
            $this->info(sprintf('Run %d completed: %d requests, %d new cases, %d updated cases.', $run->id, $run->total_requests, $run->new_cases_count, $run->updated_cases_count));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $run->refresh()->markFailed();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveCourt(string $url): ?Court
    {
        $courtId = $this->option('court_id');
        if (is_string($courtId) && $courtId !== '') {
            return Court::query()->find($courtId);
        }

        return Court::query()

            ->get()
            ->first(fn (Court $court): bool => str_starts_with($url, rtrim($court->base_url, '/')));
    }
}
