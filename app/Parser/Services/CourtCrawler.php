<?php

namespace App\Parser\Services;

use App\Models\Parser\Court;
use App\Models\Parser\ParserRun;
use App\Parser\Adapters\SudrfCourtAdapter;
use App\Parser\DTO\CalendarCaseLink;
use App\Parser\Exceptions\CalendarRequestFailedException;
use App\Parser\Exceptions\SourceCircuitOpenException;
use App\Parser\Fetcher\CourtHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class CourtCrawler
{
    public function __construct(
        private readonly SudrfCourtAdapter $adapter,
        private readonly CourtHttpClient $httpClient,
        private readonly SanitizerService $sanitizer,
        private readonly CaseUpsertService $caseUpsertService,
    ) {}

    public function crawlCourt(Court $court, CarbonImmutable $from, CarbonImmutable $to, ParserRun $run, bool $persistOutOfWindow = false): void
    {
        $seenCaseUrls = [];
        $calendarDate = $to->startOfDay();

        while ($calendarDate->gte($from)) {
            $links = $this->crawlCalendarDate($court, $calendarDate, $run);

            foreach ($links as $link) {
                if (! $this->adapter->isCivilFirstInstance($link) || isset($seenCaseUrls[$link->url])) {
                    continue;
                }

                $seenCaseUrls[$link->url] = true;
                $this->crawlCase($court, $link->url, $run, $from, $to, $persistOutOfWindow);
            }

            $calendarDate = $calendarDate->subDay();
        }

        $court->forceFill(['last_checked_at' => now(), 'last_successful_crawl_at' => now(), 'status' => 'active'])->save();
    }

    /** @return array<int, CalendarCaseLink> */
    public function crawlCalendarDate(Court $court, CarbonImmutable $calendarDate, ParserRun $run): array
    {
        $run->increment('calendar_days_count');
        $calendarUrl = $this->adapter->buildCalendarUrl($court, $calendarDate);
        $calendarResponse = $this->httpClient->fetch($court, $calendarUrl, $run);
        $this->sanitizer->rememberFetchedPage($court, $calendarResponse, 'calendar');

        if ($calendarResponse->statusCode >= 400) {
            throw new CalendarRequestFailedException($calendarUrl, $calendarResponse->statusCode);
        }

        $links = $this->adapter->parseCalendarCaseLinks($calendarResponse->body, $court->base_url, $calendarDate);
        $run->increment('calendar_case_links_count', count($links));

        return $links;
    }

    public function crawlCase(Court $court, string $url, ParserRun $run, ?CarbonImmutable $windowFrom = null, ?CarbonImmutable $windowTo = null, bool $persistOutOfWindow = true): bool
    {
        try {
            $caseResponse = $this->httpClient->fetch($court, $url, $run);
            $rawPage = $this->sanitizer->rememberFetchedPage($court, $caseResponse, 'case');

            if ($caseResponse->statusCode >= 400) {
                return false;
            }

            $parsed = $this->adapter->parseCaseCard($caseResponse->body, $url);
            $result = $this->caseUpsertService->upsert($court, $parsed, $windowFrom, $windowTo, $persistOutOfWindow, $rawPage);

            if (! $result->persisted) {
                $run->increment('out_of_window_cases_count');

                return true;
            }

            $run->increment($result->createdCase ? 'new_cases_count' : 'updated_cases_count');
            $run->increment('new_events_count', $result->newEventsCount);

            if ($result->trainingCandidate) {
                $run->increment('training_candidate_cases_count');
            } else {
                $run->increment('out_of_window_cases_count');
            }

            return true;
        } catch (SourceCircuitOpenException|ConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            $run->increment('error_count');

            return false;
        }
    }
}
