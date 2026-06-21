<?php

namespace App\Parser\Adapters;

use App\Models\Parser\Court;
use App\Parser\DTO\CalendarCaseLink;
use App\Parser\DTO\ParsedCaseInstance;
use Carbon\CarbonImmutable;

interface CourtSourceAdapter
{
    public function supports(string $baseUrl, string $html): bool;

    public function buildCalendarUrl(Court $court, CarbonImmutable $date): string;

    /** @return array<int, CalendarCaseLink> */
    public function parseCalendarCaseLinks(string $html, string $baseUrl, CarbonImmutable $date): array;

    public function parseCaseCard(string $html, string $url): ParsedCaseInstance;
}
