<?php

namespace Tests\Unit;

use App\Parser\Adapters\SudrfCourtAdapter;
use App\Parser\Normalizers\CaseNumberNormalizer;
use App\Parser\Normalizers\CategoryNormalizer;
use App\Parser\Normalizers\DateNormalizer;
use App\Parser\Normalizers\EventTypeNormalizer;
use App\Parser\Normalizers\ResultNormalizer;
use App\Parser\Services\SanitizerService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SudrfCourtAdapterTest extends TestCase
{
    public function test_it_extracts_calendar_case_links_and_identifies_civil_first_instance(): void
    {
        $adapter = $this->adapter();
        $html = file_get_contents(__DIR__.'/../Fixtures/sudrf/calendar.html');
        $links = $adapter->parseCalendarCaseLinks($html, 'https://industrialnyy--udm.sudrf.ru', CarbonImmutable::parse('2025-06-10'));

        $this->assertCount(2, $links);
        $this->assertSame('2-100/2025', $links[0]->caseNumber);
        $this->assertSame('uid-100', $links[0]->caseUid);
        $this->assertSame('1540005', $links[0]->caseTypeId);
        $this->assertTrue($adapter->isCivilFirstInstance($links[0]));
        $this->assertFalse($adapter->isCivilFirstInstance($links[1]));
    }

    public function test_it_parses_case_card_without_parties(): void
    {
        $adapter = $this->adapter();
        $html = file_get_contents(__DIR__.'/../Fixtures/sudrf/case.html');
        $url = 'https://industrialnyy--udm.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=100&case_uid=uid-100&delo_id=1540005';
        $parsed = $adapter->parseCaseCard($html, $url);

        $this->assertSame('2-100/2025', $parsed->caseNumber);
        $this->assertSame('uid-100', $parsed->caseUid);
        $this->assertSame('civil', $parsed->caseType);
        $this->assertSame('1540005', $parsed->sourceCaseTypeId);
        $this->assertSame('2025-01-10', $parsed->receivedDate?->toDateString());
        $this->assertSame('2025-02-20', $parsed->completedAt?->toDateString());
        $this->assertSame('damages', $parsed->categoryNormalized);
        $this->assertSame('partially_satisfied', $parsed->resultNormalized);
        $this->assertCount(2, $parsed->events);
        $this->assertSame('case_received', $parsed->events[0]->eventTypeNormalized);
    }

    public function test_it_parses_documents_and_party_types_without_party_names(): void
    {
        $adapter = $this->adapter();
        $html = <<<'HTML'
<!doctype html>
<html><body>
<div>Case N 2-555/2025</div>
<table>
<tr><td>uid</td><td>uid-555</td></tr>
<tr><td>received</td><td>10.01.2025</td></tr>
<tr><td>category</td><td>damages</td></tr>
<tr><td>judge</td><td>not stored</td></tr>
<tr><td>completed</td><td>20.02.2025</td></tr>
<tr><td>result</td><td>partially satisfied</td></tr>
</table>
<div id="cont3"><table>
<tr><td>plaintiff</td><td>John Smith</td></tr>
<tr><td>representative</td><td>Jane Agent</td></tr>
<tr><td>weird role</td><td>Hidden party</td></tr>
<tr><td>defendant</td><td>LLC Romashka</td></tr>
</table></div>
<div id="cont5"><a href="/modules.php?name=sud_delo&amp;name_op=doc&amp;doc_id=10">Decision N 10 from 20.02.2025</a></div>
</body></html>
HTML;
        $parsed = $adapter->parseCaseCard($html, 'https://example.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=555&case_uid=uid-555&delo_id=1540005');

        $this->assertCount(1, $parsed->documents);
        $this->assertSame('decision', $parsed->documents[0]->documentTypeNormalized);
        $this->assertCount(4, $parsed->parties);
        $this->assertSame('plaintiff', $parsed->parties[0]->role);
        $this->assertSame('claimant', $parsed->parties[0]->roleGroup);
        $this->assertSame('individual', $parsed->parties[0]->partyType);
        $this->assertFalse($parsed->parties[0]->isHidden);
        $this->assertSame('representative', $parsed->parties[1]->role);
        $this->assertSame('representative', $parsed->parties[1]->roleGroup);
        $this->assertSame('unknown', $parsed->parties[2]->role);
        $this->assertSame('unknown', $parsed->parties[2]->roleGroup);
        $this->assertSame('weird role', $parsed->parties[2]->sourceRole);
        $this->assertTrue($parsed->parties[2]->isHidden);
        $this->assertSame('defendant', $parsed->parties[3]->role);
        $this->assertSame('respondent', $parsed->parties[3]->roleGroup);
        $this->assertSame('legal_entity', $parsed->parties[3]->partyType);
    }

    public function test_it_parses_russian_party_roles_and_document_types(): void
    {
        $adapter = $this->adapter();
        $plaintiff = "\u{0418}\u{0421}\u{0422}\u{0415}\u{0426}";
        $defendant = "\u{041e}\u{0422}\u{0412}\u{0415}\u{0422}\u{0427}\u{0418}\u{041a}";
        $person = "\u{0418}\u{0432}\u{0430}\u{043d}\u{043e}\u{0432} \u{0418}\u{0432}\u{0430}\u{043d} \u{0418}\u{0432}\u{0430}\u{043d}\u{043e}\u{0432}\u{0438}\u{0447}";
        $company = "\u{041e}\u{041e}\u{041e} \u{0420}\u{043e}\u{043c}\u{0430}\u{0448}\u{043a}\u{0430}";
        $decision = "\u{0420}\u{0435}\u{0448}\u{0435}\u{043d}\u{0438}\u{0435} \u{2116} 10 \u{043e}\u{0442} 20.02.2025";
        $html = '<!doctype html><html><body>'
            .'<div>Case N 2-777/2025</div>'
            .'<table><tr><td>uid</td><td>uid-777</td></tr><tr><td>received</td><td>10.01.2025</td></tr><tr><td>category</td><td>damages</td></tr><tr><td>judge</td><td>x</td></tr><tr><td>completed</td><td>20.02.2025</td></tr><tr><td>result</td><td>done</td></tr></table>'
            .'<div id="cont3"><table><tr><td>'.$plaintiff.'</td><td>'.$person.'</td></tr><tr><td>'.$defendant.'</td><td>'.$company.'</td></tr></table></div>'
            .'<a href="/modules.php?name=sud_delo&amp;name_op=doc&amp;doc_id=77">'.$decision.'</a>'
            .'</body></html>';

        $parsed = $adapter->parseCaseCard($html, 'https://example.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=777&case_uid=uid-777&delo_id=1540005');

        $this->assertSame('decision', $parsed->documents[0]->documentTypeNormalized);
        $this->assertSame('plaintiff', $parsed->parties[0]->role);
        $this->assertSame('claimant', $parsed->parties[0]->roleGroup);
        $this->assertSame('individual', $parsed->parties[0]->partyType);
        $this->assertSame('defendant', $parsed->parties[1]->role);
        $this->assertSame('respondent', $parsed->parties[1]->roleGroup);
        $this->assertSame('legal_entity', $parsed->parties[1]->partyType);
    }

    public function test_it_marks_transfer_by_jurisdiction_as_not_resolved_dispute(): void
    {
        $adapter = $this->adapter();
        $caseTitle = "\u{0414}\u{0435}\u{043b}\u{043e} \u{2116} 2-2255/2026 ~ \u{041c}-1266/2026";
        $categoryTop = "\u{0417}\u{0430}\u{0449}\u{0438}\u{0442}\u{0430} \u{043f}\u{0440}\u{0430}\u{0432} \u{043f}\u{043e}\u{0442}\u{0440}\u{0435}\u{0431}\u{0438}\u{0442}\u{0435}\u{043b}\u{0435}\u{0439}";
        $categorySecond = "\u{041e} \u{0437}\u{0430}\u{0449}\u{0438}\u{0442}\u{0435} \u{043f}\u{0440}\u{0430}\u{0432} \u{043f}\u{043e}\u{0442}\u{0440}\u{0435}\u{0431}\u{0438}\u{0442}\u{0435}\u{043b}\u{0435}\u{0439}";
        $categoryThird = "- \u{0438}\u{0437} \u{0434}\u{043e}\u{0433}\u{043e}\u{0432}\u{043e}\u{0440}\u{043e}\u{0432} \u{0432} \u{0441}\u{0444}\u{0435}\u{0440}\u{0435}:";
        $categoryLeaf = "\u{0441}\u{0442}\u{0440}\u{043e}\u{0438}\u{0442}\u{0435}\u{043b}\u{044c}\u{043d}\u{044b}\u{0445} \u{0443}\u{0441}\u{043b}\u{0443}\u{0433}";
        $result = "\u{041f}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}\u{043d}\u{043e} \u{043f}\u{043e} \u{043f}\u{043e}\u{0434}\u{0441}\u{0443}\u{0434}\u{043d}\u{043e}\u{0441}\u{0442}\u{0438}, \u{043f}\u{043e}\u{0434}\u{0432}\u{0435}\u{0434}\u{043e}\u{043c}\u{0441}\u{0442}\u{0432}\u{0435}\u{043d}\u{043d}\u{043e}\u{0441}\u{0442}\u{0438}";
        $eventReceived = "\u{0417}\u{0430}\u{0440}\u{0435}\u{0433}\u{0438}\u{0441}\u{0442}\u{0440}\u{0438}\u{0440}\u{043e}\u{0432}\u{0430}\u{043d}\u{043e} \u{0438}\u{0441}\u{043a}\u{043e}\u{0432}\u{043e}\u{0435} \u{0437}\u{0430}\u{044f}\u{0432}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}";
        $eventHearing = "\u{0421}\u{0443}\u{0434}\u{0435}\u{0431}\u{043d}\u{043e}\u{0435} \u{0437}\u{0430}\u{0441}\u{0435}\u{0434}\u{0430}\u{043d}\u{0438}\u{0435}";
        $eventTransfer = "\u{0414}\u{0435}\u{043b}\u{043e} \u{043f}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}\u{043d}\u{043e} \u{043d}\u{0430} \u{0440}\u{0430}\u{0441}\u{0441}\u{043c}\u{043e}\u{0442}\u{0440}\u{0435}\u{043d}\u{0438}\u{0435} \u{0434}\u{0440}\u{0443}\u{0433}\u{043e}\u{0433}\u{043e} \u{0441}\u{0443}\u{0434}\u{0430}";
        $category = $categoryTop.' &#8594; '.$categorySecond.' &#8594; '.$categoryThird.' &#8594; '.$categoryLeaf;
        $html = <<<HTML
<!doctype html>
<html><body>
<div class="casenumber">{$caseTitle}</div>
<table>
<tr><td><b>uid</b></td><td>18RS0001-01-2026-001776-54</td></tr>
<tr><td><b>received</b></td><td>18.05.2026</td></tr>
<tr><td><b>category</b></td><td>{$category}</td></tr>
<tr><td><b>judge</b></td><td>not stored</td></tr>
<tr><td><b>completed</b></td><td>19.06.2026</td></tr>
<tr><td><b>result</b></td><td>{$result}</td></tr>
</table>
<div id="cont2"><table>
<tr><th colspan="8">history</th></tr>
<tr><td><b>event</b></td><td><b>date</b></td><td><b>time</b></td><td><b>place</b></td><td><b>result</b></td><td><b>reason</b></td><td><b>note</b></td><td><b>published</b></td></tr>
<tr><td>{$eventReceived}</td><td>18.05.2026</td><td>10:43</td><td></td><td></td><td></td><td></td><td>18.05.2026</td></tr>
<tr><td>{$eventHearing}</td><td>19.06.2026</td><td>10:00</td><td>9</td><td>{$eventTransfer}</td><td></td><td></td><td>19.05.2026</td></tr>
</table></div>
</body></html>
HTML;
        $parsed = $adapter->parseCaseCard($html, 'https://leninskiy.udm.sudrf.ru/modules.php?name=sud_delo&srv_num=1&name_op=case&case_id=352601970&case_uid=6e0e8ac3-4070-4fec-8ede-50d4e05aa032&delo_id=1540005&new=.');

        $this->assertSame('civil', $parsed->caseType);
        $this->assertSame('1540005', $parsed->sourceCaseTypeId);
        $this->assertSame('transferred', $parsed->courtInstanceStatusNormalized);
        $this->assertSame('transferred', $parsed->disputeStatusNormalized);
        $this->assertSame('transferred_by_jurisdiction', $parsed->dispositionType);
        $this->assertSame('transferred_by_jurisdiction', $parsed->resultNormalized);
        $this->assertSame('2026-06-19', $parsed->completedAt?->toDateString());
        $this->assertSame('consumer_protection', $parsed->categoryNormalized);
        $this->assertSame([$categoryTop, $categorySecond, $categoryThird, $categoryLeaf], $parsed->categoryPath);
        $this->assertSame('case_transferred_to_another_court', $parsed->events[1]->eventTypeNormalized);
        $this->assertSame('transferred_by_jurisdiction', $parsed->events[1]->eventResultNormalized);
    }

    public function test_it_keeps_postponed_case_active_when_final_result_is_absent(): void
    {
        $adapter = $this->adapter();
        $category = "\u{0414}\u{0435}\u{043b}\u{0430} \u{043e}\u{0441}\u{043e}\u{0431}\u{043e}\u{0433}\u{043e} \u{043f}\u{0440}\u{043e}\u{0438}\u{0437}\u{0432}\u{043e}\u{0434}\u{0441}\u{0442}\u{0432}\u{0430}";
        $eventName = "\u{0421}\u{0443}\u{0434}\u{0435}\u{0431}\u{043d}\u{043e}\u{0435} \u{0437}\u{0430}\u{0441}\u{0435}\u{0434}\u{0430}\u{043d}\u{0438}\u{0435}";
        $eventResult = "\u{0417}\u{0430}\u{0441}\u{0435}\u{0434}\u{0430}\u{043d}\u{0438}\u{0435} \u{043e}\u{0442}\u{043b}\u{043e}\u{0436}\u{0435}\u{043d}\u{043e}";
        $html = <<<HTML
<!doctype html>
<html><body>
<div>Case N 2-200/2026</div>
<table>
<tr><td>uid</td><td>uid-200</td></tr>
<tr><td>received</td><td>15.05.2026</td></tr>
<tr><td>ignored extra key</td><td>this must not become category or result</td></tr>
<tr><td>category</td><td>{$category}</td></tr>
</table>
<table>
<tr><td><b>event</b></td><td><b>date</b></td><td><b>time</b></td><td><b>place</b></td><td><b>result</b></td></tr>
<tr><td>{$eventName}</td><td>22.06.2026</td><td>11:40</td><td>9</td><td>{$eventResult}</td></tr>
</table>
</body></html>
HTML;

        $parsed = $adapter->parseCaseCard($html, 'https://example.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=200&case_uid=uid-200&delo_id=1540005');

        $this->assertSame('active', $parsed->courtInstanceStatusNormalized);
        $this->assertSame('active', $parsed->disputeStatusNormalized);
        $this->assertNull($parsed->completedAt);
        $this->assertSame('postponed', $parsed->resultNormalized);
        $this->assertSame($category, $parsed->categoryRaw);
    }
    public function test_sanitizer_removes_parties_block_from_case_html(): void
    {
        $html = file_get_contents(__DIR__.'/../Fixtures/sudrf/case.html');
        $sanitized = (new SanitizerService)->sanitizeCaseHtml($html);

        $this->assertStringNotContainsString('id="cont3"', $sanitized);
        $this->assertStringContainsString('id="cont2"', $sanitized);
    }

    private function adapter(): SudrfCourtAdapter
    {
        return new SudrfCourtAdapter(
            new DateNormalizer,
            new CaseNumberNormalizer,
            new CategoryNormalizer,
            new EventTypeNormalizer,
            new ResultNormalizer,
        );
    }
}
