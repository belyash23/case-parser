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
        $normalizedCaseNumberAliases = $this->normalizedCaseNumberAliases($plainText);
        $details = $this->extractDetails($xpath);
        $events = $this->extractEvents($xpath, $url);
        $documents = $this->extractDocuments($xpath, $url);
        $parties = $this->extractParties($xpath);
        $resultRaw = $details['result'] ?? $this->lastEventResult($events);
        $resultNormalized = $this->resultNormalizer->normalize($resultRaw);
        $isTransferred = $resultNormalized === 'transferred_by_jurisdiction' || $this->hasTransferEvent($events);
        $completedAt = $this->dateNormalizer->normalize($details['completed_at'] ?? null)
            ?? $this->inferCompletedAt($events);
        $receivedDate = $this->dateNormalizer->normalize($details['received_date'] ?? null);
        $categoryRaw = $details['category'] ?? null;
        $categoryPath = $this->categoryNormalizer->path($categoryRaw);

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $sourceCaseTypeId = isset($query['delo_id']) ? (string) $query['delo_id'] : null;
        $courtInstanceStatus = $this->courtInstanceStatus($completedAt, $isTransferred);

        return new ParsedCaseInstance(
            sourceUrl: $url,
            caseNumber: $caseNumber,
            normalizedCaseNumber: $this->caseNumberNormalizer->normalize($caseNumber),
            normalizedCaseNumberAliases: $normalizedCaseNumberAliases,
            caseUid: $query['case_uid'] ?? $details['uid'] ?? null,
            externalCaseId: $query['case_id'] ?? null,
            sourceCaseTypeId: $sourceCaseTypeId,
            caseType: $this->caseType($sourceCaseTypeId),
            instanceLevel: 'first',
            courtInstanceStatusRaw: $resultRaw ?? $courtInstanceStatus,
            courtInstanceStatusNormalized: $courtInstanceStatus,
            disputeStatusNormalized: $this->disputeStatus($completedAt, $resultNormalized, $isTransferred),
            dispositionType: $this->dispositionType($resultNormalized, $completedAt),
            resultRaw: $resultRaw,
            resultNormalized: $resultNormalized,
            receivedDate: $receivedDate,
            completedAt: $completedAt,
            categoryRaw: $categoryRaw,
            categoryNormalized: $this->categoryNormalizer->normalize($categoryRaw),
            categoryPath: $categoryPath,
            events: $events,
            documents: $documents,
            parties: $parties,
        );
    }

    /** @return array<string, string> */
    private function extractDetails(DOMXPath $xpath): array
    {
        $details = [];

        foreach ($xpath->query('//tr[count(td) = 2]') as $row) {
            if (! $row instanceof DOMElement || $this->isWithinPartiesBlock($row)) {
                continue;
            }

            $cells = $row->getElementsByTagName('td');
            $key = $this->normalizeDetailKey(Html::text($cells->item(0)));
            $value = Html::text($cells->item(1));

            if ($key === null || $value === '') {
                continue;
            }

            $details[$key] = $value;
        }

        return $details;
    }

    private function normalizeDetailKey(string $label): ?string
    {
        $label = mb_strtolower(Html::normalizeText($label));

        return match (true) {
            $label === 'uid' || str_contains($label, "\u{0443}\u{043d}\u{0438}\u{043a}\u{0430}\u{043b}\u{044c}\u{043d}\u{044b}\u{0439} \u{0438}\u{0434}\u{0435}\u{043d}\u{0442}\u{0438}\u{0444}\u{0438}\u{043a}\u{0430}\u{0442}\u{043e}\u{0440}") => 'uid',
            $label === 'received' || str_contains($label, "\u{0434}\u{0430}\u{0442}\u{0430} \u{043f}\u{043e}\u{0441}\u{0442}\u{0443}\u{043f}\u{043b}\u{0435}\u{043d}\u{0438}\u{044f}") => 'received_date',
            $label === 'category' || str_contains($label, "\u{043a}\u{0430}\u{0442}\u{0435}\u{0433}\u{043e}\u{0440}\u{0438}\u{044f} \u{0434}\u{0435}\u{043b}\u{0430}") => 'category',
            $label === 'completed' || str_contains($label, "\u{0434}\u{0430}\u{0442}\u{0430} \u{0440}\u{0430}\u{0441}\u{0441}\u{043c}\u{043e}\u{0442}\u{0440}\u{0435}\u{043d}\u{0438}\u{044f}") => 'completed_at',
            $label === 'result' || str_contains($label, "\u{0440}\u{0435}\u{0437}\u{0443}\u{043b}\u{044c}\u{0442}\u{0430}\u{0442} \u{0440}\u{0430}\u{0441}\u{0441}\u{043c}\u{043e}\u{0442}\u{0440}\u{0435}\u{043d}\u{0438}\u{044f}") => 'result',
            default => null,
        };
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
            );
        }

        return $documents;
    }

    /** @return array<int, ParsedCaseParty> */
    private function extractParties(DOMXPath $xpath): array
    {
        $parties = [];

        foreach ($this->partyTables($xpath) as $table) {
            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = $row->getElementsByTagName('td');

                if ($cells->length < 1) {
                    continue;
                }

                $sourceRole = Html::text($cells->item(0));
                $partyText = Html::text($cells->item(1));

                if ($sourceRole === '' || $this->isPartyHeaderRole($sourceRole)) {
                    continue;
                }

                $role = $this->normalizePartyRole($sourceRole);
                $isHidden = $this->isHiddenParty($partyText);
                $partyType = $isHidden ? 'unknown' : $this->classifyPartyType($partyText);

                $parties[] = new ParsedCaseParty(
                    role: $role['role'],
                    roleGroup: $role['group'],
                    partyType: $partyType,
                    isHidden: $isHidden,
                    sourceRole: $this->safeSourceRole($sourceRole),
                    confidence: $isHidden ? 0 : $this->partyClassificationConfidence($partyText),
                );
            }
        }

        return $parties;
    }

    /** @return array<int, DOMElement> */
    private function partyTables(DOMXPath $xpath): array
    {
        $tables = [];

        foreach ($xpath->query('//table') as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $text = mb_strtolower(Html::normalizeText($table->textContent ?? ''));
            if (str_contains($text, "\u{0432}\u{0438}\u{0434} \u{043b}\u{0438}\u{0446}\u{0430}, \u{0443}\u{0447}\u{0430}\u{0441}\u{0442}\u{0432}\u{0443}\u{044e}\u{0449}\u{0435}\u{0433}\u{043e} \u{0432} \u{0434}\u{0435}\u{043b}\u{0435}")
                || str_contains($text, "\u{0444}\u{0430}\u{043c}\u{0438}\u{043b}\u{0438}\u{044f} / \u{043d}\u{0430}\u{0438}\u{043c}\u{0435}\u{043d}\u{043e}\u{0432}\u{0430}\u{043d}\u{0438}\u{0435}")) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function isPartyHeaderRole(string $sourceRole): bool
    {
        $role = mb_strtolower(Html::normalizeText($sourceRole));

        return str_contains($role, "\u{0432}\u{0438}\u{0434} \u{043b}\u{0438}\u{0446}\u{0430}")
            || str_contains($role, 'role');
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

    /** @return array{role:string, group:string} */
    private function normalizePartyRole(string $sourceRole): array
    {
        $lower = mb_strtolower(Html::normalizeText($sourceRole));

        return match (true) {
            str_contains($lower, "\u{0442}\u{0440}\u{0435}\u{0442}\u{044c}\u{0435} \u{043b}\u{0438}\u{0446}\u{043e}")
                && str_contains($lower, "\u{043d}\u{0435} \u{0437}\u{0430}\u{044f}\u{0432}\u{043b}") => ['role' => 'third_party_without_claims', 'group' => 'dependent_party'],
            str_contains($lower, "\u{0442}\u{0440}\u{0435}\u{0442}\u{044c}\u{0435} \u{043b}\u{0438}\u{0446}\u{043e}")
                && str_contains($lower, "\u{0441}\u{0430}\u{043c}\u{043e}\u{0441}\u{0442}\u{043e}\u{044f}\u{0442}\u{0435}\u{043b}\u{044c}\u{043d}") => ['role' => 'third_party_with_claims', 'group' => 'independent_party'],
            $this->containsAny($lower, ['plaintiff', self::RU_PLAINTIFF]) => ['role' => 'plaintiff', 'group' => 'claimant'],
            $this->containsAny($lower, ['applicant', self::RU_CLAIMANT]) => ['role' => 'applicant', 'group' => 'claimant'],
            $this->containsAny($lower, ['defendant', self::RU_DEFENDANT]) => ['role' => 'defendant', 'group' => 'respondent'],
            str_contains($lower, "\u{0437}\u{0430}\u{0438}\u{043d}\u{0442}\u{0435}\u{0440}\u{0435}\u{0441}\u{043e}\u{0432}\u{0430}\u{043d}\u{043d}\u{043e}\u{0435} \u{043b}\u{0438}\u{0446}\u{043e}") => ['role' => 'interested_party', 'group' => 'affected_party'],
            str_contains($lower, "\u{0442}\u{0440}\u{0435}\u{0442}\u{044c}\u{0435} \u{043b}\u{0438}\u{0446}\u{043e}") || str_contains($lower, 'third') => ['role' => 'third_party', 'group' => 'third_party'],
            str_contains($lower, "\u{043f}\u{0440}\u{0435}\u{0434}\u{0441}\u{0442}\u{0430}\u{0432}\u{0438}\u{0442}\u{0435}\u{043b}\u{044c}") => ['role' => 'representative', 'group' => 'representative'],
            str_contains($lower, "\u{043f}\u{0440}\u{043e}\u{043a}\u{0443}\u{0440}\u{043e}\u{0440}") => ['role' => 'prosecutor', 'group' => 'public_participant'],
            str_contains($lower, "\u{043e}\u{0440}\u{0433}\u{0430}\u{043d} \u{043e}\u{043f}\u{0435}\u{043a}\u{0438}") => ['role' => 'guardianship_authority', 'group' => 'government_authority'],
            str_contains($lower, "\u{0432}\u{0437}\u{044b}\u{0441}\u{043a}\u{0430}\u{0442}\u{0435}\u{043b}\u{044c}") => ['role' => 'claimant_in_order_proceeding', 'group' => 'order_proceeding'],
            str_contains($lower, "\u{0434}\u{043e}\u{043b}\u{0436}\u{043d}\u{0438}\u{043a}") => ['role' => 'debtor', 'group' => 'respondent'],
            default => ['role' => 'unknown', 'group' => 'unknown'],
        };
    }

    private function isHiddenParty(string $partyText): bool
    {
        $lower = mb_strtolower(Html::normalizeText($partyText));

        return $lower === ''
            || str_contains($lower, "\u{0441}\u{043a}\u{0440}\u{044b}\u{0442}")
            || str_contains($lower, "\u{043f}\u{0435}\u{0440}\u{0441}\u{043e}\u{043d}\u{0430}\u{043b}\u{044c}\u{043d}\u{044b}\u{0435} \u{0434}\u{0430}\u{043d}\u{043d}\u{044b}\u{0435}")
            || str_contains($lower, "\u{0434}\u{0430}\u{043d}\u{043d}\u{044b}\u{0435} \u{0438}\u{0437}\u{044a}\u{044f}\u{0442}\u{044b}");
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
        return $this->extractCaseNumbers($plainText)[0] ?? null;
    }

    /** @return array<int, string> */
    private function extractCaseNumbers(string $plainText): array
    {
        preg_match_all('/(?:\b|[\x{2116}N]\s*)((?:[0-9]{1,4}-[0-9\p{L}.\-\/]+|[\p{L}]{1,3}-?[0-9]{1,6})\/[0-9]{4})\b/u', $plainText, $matches);
        return collect($matches[1] ?? [])
            ->map(fn (string $number): string => Html::normalizeText($number))
            ->filter(fn (string $number): bool => $number !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function normalizedCaseNumberAliases(string $plainText): array
    {
        return collect($this->extractCaseNumbers($plainText))
            ->map(fn (string $number): ?string => $this->caseNumberNormalizer->normalize($number))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
            if (in_array($events[$i]->eventTypeNormalized, ['decision_issued', 'returned', 'case_transferred_to_another_court'], true)) {
                return $events[$i]->eventDate;
            }
        }

        return null;
    }

    /** @param array<int, ParsedCaseEvent> $events */
    private function hasTransferEvent(array $events): bool
    {
        foreach ($events as $event) {
            if ($event->eventTypeNormalized === 'case_transferred_to_another_court'
                || $event->eventResultNormalized === 'transferred_by_jurisdiction') {
                return true;
            }
        }

        return false;
    }

    private function courtInstanceStatus(?CarbonImmutable $completedAt, bool $isTransferred): string
    {
        if ($isTransferred) {
            return 'transferred';
        }

        return $completedAt !== null ? 'closed' : 'active';
    }

    private function disputeStatus(?CarbonImmutable $completedAt, ?string $resultNormalized, bool $isTransferred): string
    {
        if ($isTransferred) {
            return 'transferred';
        }

        if ($completedAt === null) {
            return 'active';
        }

        return match ($resultNormalized) {
            'scheduled', 'postponed', null => 'active',
            default => 'resolved',
        };
    }

    private function dispositionType(?string $resultNormalized, ?CarbonImmutable $completedAt): ?string
    {
        if ($resultNormalized === null) {
            return $completedAt !== null ? 'unknown_closed' : null;
        }

        return $resultNormalized;
    }

    private function caseType(?string $caseTypeId): ?string
    {
        return match ((string) $caseTypeId) {
            self::CIVIL_FIRST_CASE_TYPE_ID => 'civil',
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
