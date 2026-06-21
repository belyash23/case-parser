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
        $this->assertSame('civil_first', $parsed->proceedingType);
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
<tr><td>defendant</td><td>LLC Romashka</td></tr>
</table></div>
<div id="cont5"><a href="/modules.php?name=sud_delo&amp;name_op=doc&amp;doc_id=10">Decision N 10 from 20.02.2025</a></div>
</body></html>
HTML;
        $parsed = $adapter->parseCaseCard($html, 'https://example.sudrf.ru/modules.php?name=sud_delo&name_op=case&case_id=555&case_uid=uid-555&delo_id=1540005');

        $this->assertCount(1, $parsed->documents);
        $this->assertSame('decision', $parsed->documents[0]->documentTypeNormalized);
        $this->assertCount(2, $parsed->parties);
        $this->assertSame('plaintiff', $parsed->parties[0]->role);
        $this->assertSame('individual', $parsed->parties[0]->partyType);
        $this->assertSame('defendant', $parsed->parties[1]->role);
        $this->assertSame('legal_entity', $parsed->parties[1]->partyType);
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
        $this->assertSame('individual', $parsed->parties[0]->partyType);
        $this->assertSame('defendant', $parsed->parties[1]->role);
        $this->assertSame('legal_entity', $parsed->parties[1]->partyType);
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
