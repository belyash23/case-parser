<?php

namespace App\Parser\Adapters;

use App\Models\Parser\Court;
use App\Parser\DTO\CalendarCaseLink;
use App\Parser\DTO\ParsedCaseEvent;
use App\Parser\DTO\ParsedCaseInstance;
use App\Parser\DTO\ParsedCaseParty;
use App\Parser\DTO\ParsedDocument;
use App\Parser\Normalizers\CaseNumberNormalizer;
use App\Parser\Normalizers\CategoryNormalizer;
use App\Parser\Normalizers\DateNormalizer;
use App\Parser\Normalizers\EventTypeNormalizer;
use App\Parser\Normalizers\ResultNormalizer;
use App\Parser\Support\Html;
use Carbon\CarbonImmutable;
use DOMElement;
use DOMXPath;

class SudrfCourtAdapter implements CourtSourceAdapter
{
    private const CIVIL_FIRST_CASE_TYPE_ID = '1540005';

    private const RU_DECISION = "\u{0440}\u{0435}\u{0448}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_RULING = "\u{043e}\u{043f}\u{0440}\u{0435}\u{0434}\u{0435}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_RESOLUTION = "\u{043f}\u{043e}\u{0441}\u{0442}\u{0430}\u{043d}\u{043e}\u{0432}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_COURT_ORDER = "\u{0441}\u{0443}\u{0434}\u{0435}\u{0431}\u{043d}\u{044b}\u{0439} \u{043f}\u{0440}\u{0438}\u{043a}\u{0430}\u{0437}";

    private const RU_PLAINTIFF = "\u{0438}\u{0441}\u{0442}\u{0435}\u{0446}";

    private const RU_CLAIMANT = "\u{0437}\u{0430}\u{044f}\u{0432}\u{0438}\u{0442}\u{0435}\u{043b}\u{044c}";

    private const RU_DEFENDANT = "\u{043e}\u{0442}\u{0432}\u{0435}\u{0442}\u{0447}\u{0438}\u{043a}";

    private const RU_THIRD = "\u{0442}\u{0440}\u{0435}\u{0442}\u{044c}";

    private const RU_INTERESTED = "\u{0437}\u{0430}\u{0438}\u{043d}\u{0442}\u{0435}\u{0440}\u{0435}\u{0441}";

    private const RU_INDIVIDUAL_ENTREPRENEUR = "\u{0438}\u{043f}";

    public function __construct(
        private readonly DateNormalizer $dateNormalizer,
        private readonly CaseNumberNormalizer $caseNumberNormalizer,
        private readonly CategoryNormalizer $categoryNormalizer,
        private readonly EventTypeNormalizer $eventTypeNormalizer,
        private readonly ResultNormalizer $resultNormalizer,
    ) {}

    public function supports(string $baseUrl, string $html): bool
    {
        return str_contains($baseUrl, '.sudrf.ru') && str_contains($html, 'name=sud_delo');
    }

    public function buildCalendarUrl(Court $court, CarbonImmutable $date): string
    {
        return rtrim($court->base_url, '/').'/modules.php?name=sud_delo&srv_num=1&H_date='.$date->format('d.m.Y');
    }

    public function isCivilFirstInstance(CalendarCaseLink $link): bool
    {
        return $link->caseTypeId === self::CIVIL_FIRST_CASE_TYPE_ID;
    }

    /** @return array<int, CalendarCaseLink> */
    public function parseCalendarCaseLinks(string $html, string $baseUrl, CarbonImmutable $date): array
    {
        $xpath = Html::xpath($html);
        $links = [];
        $seen = [];

        foreach ($xpath->query('//a[contains(@href, "name_op=case") and contains(@href, "case_id=")]') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode($anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = Html::absoluteUrl($baseUrl, $href);
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $caseNumber = Html::text($anchor);

            if ($caseNumber === '' || isset($seen[$url])) {
                continue;
            }

            $scheduledTime = null;
            $row = $anchor->parentNode?->parentNode;
            if ($row instanceof DOMElement) {
                $cells = [];
                foreach ($row->getElementsByTagName('td') as $cell) {
                    $cells[] = Html::text($cell);
                }
                $scheduledTime = $cells[2] ?? null;
            }

            $seen[$url] = true;
            $links[] = new CalendarCaseLink(
                url: $url,
                caseNumber: $caseNumber,
                caseUid: $query['case_uid'] ?? null,
                externalCaseId: $query['case_id'] ?? null,
                caseTypeId: isset($query['delo_id']) ? (string) $query['delo_id'] : null,
                scheduledDate: $date,
                scheduledTime: $scheduledTime !== '' ? $scheduledTime : null,
            );
        }

        return $links;
    }

    public function parseCaseCard(string $html, string $url): ParsedCaseInstance
    {
        $xpath = Html::xpath($html);
        $plainText = Html::normalizeText(strip_tags($html));
        $caseNumber = $this->extractCaseNumber($plainText);
        $details = $this->extractDetails($xpath);
        $events = $this->extractEvents($xpath, $url);
        $documents = $this->extractDocuments($xpath, $url);
        $parties = $this->extractParties($xpath);
        $resultRaw = $details['result'] ?? $this->lastEventResult($events);
        $completedAt = $this->dateNormalizer->normalize($details['completed_at'] ?? null)
            ?? $this->inferCompletedAt($events);
        $receivedDate = $this->dateNormalizer->normalize($details['received_date'] ?? null);
        $categoryRaw = $details['category'] ?? null;

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        return new ParsedCaseInstance(
            sourceUrl: $url,
            caseNumber: $caseNumber,
            normalizedCaseNumber: $this->caseNumberNormalizer->normalize($caseNumber),
            caseUid: $query['case_uid'] ?? $details['uid'] ?? null,
            externalCaseId: $query['case_id'] ?? null,
            proceedingType: $this->proceedingType($query['delo_id'] ?? null),
            instanceLevel: 'first',
            statusRaw: $completedAt !== null ? 'completed' : 'active',
            statusNormalized: $completedAt !== null ? 'completed' : 'active',
            resultRaw: $resultRaw,
            resultNormalized: $this->resultNormalizer->normalize($resultRaw),
            receivedDate: $receivedDate,
            completedAt: $completedAt,
            categoryRaw: $categoryRaw,
            categoryNormalized: $this->categoryNormalizer->normalize($categoryRaw),
            events: $events,
            documents: $documents,
            parties: $parties,
        );
    }

    /** @return array<string, string> */
    private function extractDetails(DOMXPath $xpath): array
    {
        $details = [];
        $fallbackKeys = ['uid', 'received_date', 'category', null, 'completed_at', 'result'];
        $fallbackIndex = 0;

        foreach ($xpath->query('//tr[count(td) >= 2]') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $row->getElementsByTagName('td');
            if ($cells->length >= 5 || $this->isWithinPartiesBlock($row)) {
                continue;
            }

            $value = Html::text($cells->item(1));
            if ($value === '' || ! array_key_exists($fallbackIndex, $fallbackKeys)) {
                continue;
            }

            $fallbackKey = $fallbackKeys[$fallbackIndex];
            if ($fallbackKey !== null) {
                $details[$fallbackKey] = $value;
            }

            $fallbackIndex++;
        }

        return $details;
    }

    /** @return array<int, ParsedCaseEvent> */
    private function extractEvents(DOMXPath $xpath, string $url): array
    {
        $events = [];
        $table = null;

        foreach ($xpath->query('//table') as $candidateTable) {
            if (! $candidateTable instanceof DOMElement || $this->isWithinPartiesBlock($candidateTable)) {
                continue;
            }

            foreach ($candidateTable->getElementsByTagName('tr') as $candidateRow) {
                if ($candidateRow->getElementsByTagName('td')->length >= 5) {
                    $table = $candidateTable;
                    break 2;
                }
            }
        }

        if (! $table instanceof DOMElement) {
            return [];
        }

        $order = 0;
        foreach ($table->getElementsByTagName('tr') as $row) {
            if ($row->getElementsByTagName('th')->length > 0) {
                continue;
            }

            $cells = $row->getElementsByTagName('td');
            if ($cells->length < 2) {
                continue;
            }

            $eventName = Html::text($cells->item(0));
            $eventDate = $this->dateNormalizer->normalize(Html::text($cells->item(1)));
            if ($eventName === '' || ($order === 0 && $eventDate === null)) {
                continue;
            }

            $eventTime = Html::text($cells->item(2));
            $eventResult = $cells->length >= 5 ? Html::text($cells->item(4)) : null;

            $order++;
            $events[] = new ParsedCaseEvent(
                order: $order,
                eventDate: $eventDate,
                eventTime: $eventTime !== '' ? $eventTime : null,
                eventTypeRaw: $eventName,
                eventTypeNormalized: $this->eventTypeNormalizer->normalize($eventName, $eventResult),
                eventResultRaw: $eventResult !== '' ? $eventResult : null,
                eventResultNormalized: $this->resultNormalizer->normalize($eventResult),
                sourceUrl: $url,
            );
        }

        return $events;
    }

    /** @return array<int, ParsedDocument> */
    private function extractDocuments(DOMXPath $xpath, string $caseUrl): array
    {
        $documents = [];
        $seen = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode($anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = Html::text($anchor);

            if (! str_contains($href, 'name_op=doc') && ! $this->looksLikeDocumentLink($text, $href)) {
                continue;
            }

            $url = Html::absoluteUrl($caseUrl, $href);
            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $documents[] = new ParsedDocument(
                documentTypeRaw: $text !== '' ? $text : null,
                documentTypeNormalized: $this->normalizeDocumentType($text.' '.$href),
                documentNumber: $this->extractDocumentNumber($text),
                documentDate: $this->dateNormalizer->normalize($text),
                documentKind: $this->normalizeDocumentKind($text.' '.$href),
                sourceUrl: $url,
            );
        }

        return $documents;
    }

    /** @return array<int, ParsedCaseParty> */
    private function extractParties(DOMXPath $xpath): array
    {
        $parties = [];

        foreach ($xpath->query('//*[@id="cont3" or @id="tab3"]//tr[count(td) >= 2] | //tr[count(td) >= 2]') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $row->getElementsByTagName('td');
            $sourceRole = Html::text($cells->item(0));
            $partyText = Html::text($cells->item(1));
            $role = $this->normalizePartyRole($sourceRole);

            if ($role === null || $partyText === '') {
                continue;
            }

            $parties[] = new ParsedCaseParty(
                role: $role,
                partyType: $this->classifyPartyType($partyText),
                sourceRole: $this->safeSourceRole($sourceRole),
                confidence: $this->partyClassificationConfidence($partyText),
            );
        }

        return $parties;
    }

    private function isWithinPartiesBlock(DOMElement $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            if (in_array($current->getAttribute('id'), ['cont3', 'tab3'], true)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeDocumentLink(string $text, string $href): bool
    {
        $lower = mb_strtolower(Html::normalizeText($text.' '.$href));

        return $this->containsAny($lower, [
            self::RU_DECISION,
            self::RU_RULING,
            self::RU_RESOLUTION,
            self::RU_COURT_ORDER,
            'decision',
            'ruling',
            'resolution',
            'document',
            'doc_id',
        ]);
    }

    private function normalizeDocumentType(string $text): ?string
    {
        $lower = mb_strtolower(Html::normalizeText($text));

        return match (true) {
            $lower === '' => null,
            $this->containsAny($lower, [self::RU_DECISION, 'decision', 'name_op=doc']) => 'decision',
            $this->containsAny($lower, [self::RU_RULING, 'ruling']) => 'ruling',
            $this->containsAny($lower, [self::RU_RESOLUTION, 'resolution']) => 'resolution',
            str_contains($lower, self::RU_COURT_ORDER) => 'court_order',
            default => 'other',
        };
    }

    private function normalizeDocumentKind(string $text): ?string
    {
        return $this->normalizeDocumentType($text);
    }

    private function extractDocumentNumber(string $text): ?string
    {
        if (preg_match('/(?:N|No|#|\x{2116})\s*([^,;]+)/u', $text, $matches) === 1) {
            return Html::normalizeText($matches[1]);
        }

        return null;
    }

    private function normalizePartyRole(string $sourceRole): ?string
    {
        $lower = mb_strtolower(Html::normalizeText($sourceRole));

        return match (true) {
            $this->containsAny($lower, ['plaintiff', 'claimant', self::RU_PLAINTIFF, self::RU_CLAIMANT]) => 'plaintiff',
            $this->containsAny($lower, ['defendant', self::RU_DEFENDANT]) => 'defendant',
            $this->containsAny($lower, ['third', self::RU_THIRD, self::RU_INTERESTED]) => 'third_party',
            default => null,
        };
    }

    private function classifyPartyType(string $partyText): string
    {
        $text = Html::normalizeText($partyText);
        $lower = mb_strtolower($text);

        if ($this->containsAny($lower, $this->governmentPartyMarkers())) {
            return 'government';
        }

        if ($this->containsAny($lower, $this->legalEntityPartyMarkers())) {
            return 'legal_entity';
        }

        if ($this->looksLikeIndividualParty($text)) {
            return 'individual';
        }

        return 'unknown';
    }

    private function partyClassificationConfidence(string $partyText): int
    {
        return match ($this->classifyPartyType($partyText)) {
            'government', 'legal_entity' => 85,
            'individual' => 70,
            default => 30,
        };
    }

    private function safeSourceRole(string $sourceRole): ?string
    {
        $role = Html::normalizeText($sourceRole);

        return $role !== '' ? mb_substr($role, 0, 120) : null;
    }

    private function extractCaseNumber(string $plainText): ?string
    {
        if (preg_match('/\b([0-9]{1,4}-[0-9A-Za-z.\-\/]+\/[0-9]{4})\b/u', $plainText, $matches) === 1) {
            return Html::normalizeText($matches[1]);
        }

        if (preg_match('/\bN\s*([0-9]{1,4}-[0-9A-Za-z.\-\/]+\/[0-9]{4})\b/u', $plainText, $matches) === 1) {
            return Html::normalizeText($matches[1]);
        }

        return null;
    }

    /** @param array<int, ParsedCaseEvent> $events */
    private function lastEventResult(array $events): ?string
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]->eventResultRaw !== null) {
                return $events[$i]->eventResultRaw;
            }
        }

        return null;
    }

    /** @param array<int, ParsedCaseEvent> $events */
    private function inferCompletedAt(array $events): ?CarbonImmutable
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (in_array($events[$i]->eventTypeNormalized, ['decision_issued', 'returned'], true)) {
                return $events[$i]->eventDate;
            }
        }

        return null;
    }

    private function proceedingType(?string $caseTypeId): ?string
    {
        return match ((string) $caseTypeId) {
            self::CIVIL_FIRST_CASE_TYPE_ID => 'civil_first',
            default => $caseTypeId !== null ? 'sudrf_'.$caseTypeId : null,
        };
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function legalEntityPartyMarkers(): array
    {
        return [
            "\u{043e}\u{043e}\u{043e}",
            "\u{0430}\u{043e}",
            "\u{043f}\u{0430}\u{043e}",
            "\u{0437}\u{0430}\u{043e}",
            "\u{043e}\u{0430}\u{043e}",
            "\u{0431}\u{0430}\u{043d}\u{043a}",
            "\u{0441}\u{0442}\u{0440}\u{0430}\u{0445}\u{043e}\u{0432}",
            "\u{043e}\u{0431}\u{0449}\u{0435}\u{0441}\u{0442}\u{0432}\u{043e}",
            "\u{043a}\u{043e}\u{043c}\u{043f}\u{0430}\u{043d}\u{0438}\u{044f}",
            'llc',
            'ltd',
            'inc',
            'corp',
        ];
    }

    /** @return array<int, string> */
    private function governmentPartyMarkers(): array
    {
        return [
            "\u{0430}\u{0434}\u{043c}\u{0438}\u{043d}\u{0438}\u{0441}\u{0442}\u{0440}",
            "\u{043c}\u{0438}\u{043d}\u{0438}\u{0441}\u{0442}\u{0435}\u{0440}\u{0441}\u{0442}\u{0432}\u{043e}",
            "\u{0443}\u{043f}\u{0440}\u{0430}\u{0432}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}",
            "\u{0434}\u{0435}\u{043f}\u{0430}\u{0440}\u{0442}\u{0430}\u{043c}\u{0435}\u{043d}\u{0442}",
            "\u{0441}\u{043b}\u{0443}\u{0436}\u{0431}\u{0430}",
            "\u{043f}\u{0440}\u{0438}\u{0441}\u{0442}\u{0430}\u{0432}",
            "\u{043a}\u{0430}\u{0437}\u{0435}\u{043d}\u{043d}",
            "\u{0431}\u{044e}\u{0434}\u{0436}\u{0435}\u{0442}\u{043d}",
            "\u{0443}\u{0447}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043d}\u{0438}\u{0435}",
            "\u{043c}\u{0443}\u{043d}\u{0438}\u{0446}\u{0438}\u{043f}",
            'government',
            'ministry',
            'administration',
            'department',
            'agency',
        ];
    }

    private function looksLikeIndividualParty(string $text): bool
    {
        if (str_starts_with(mb_strtolower(trim($text)), self::RU_INDIVIDUAL_ENTREPRENEUR.' ')) {
            return true;
        }

        return preg_match('/^[\p{Lu}][\p{Ll}]+\s+[\p{Lu}][\p{Ll}]+(?:\s+[\p{Lu}][\p{Ll}]+)?$/u', $text) === 1
            || preg_match('/^[a-z]+\s+[a-z]+(?:\s+[a-z]+)?$/iu', $text) === 1;
    }
}
