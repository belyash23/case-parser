<?php

namespace Tests\Unit;

use App\Parser\Normalizers\CaseNumberNormalizer;
use App\Parser\Normalizers\CategoryNormalizer;
use App\Parser\Normalizers\DateNormalizer;
use App\Parser\Normalizers\EventTypeNormalizer;
use App\Parser\Normalizers\ResultNormalizer;
use PHPUnit\Framework\TestCase;

class ParserNormalizersTest extends TestCase
{
    public function test_date_normalizer_supports_sudrf_dates(): void
    {
        $normalizer = new DateNormalizer;

        $this->assertSame('2025-06-10', $normalizer->normalize('10.06.2025')?->toDateString());
        $this->assertSame('2025-06-10 12:30:00', $normalizer->normalize('10.06.2025 12:30')?->toDateTimeString());
        $this->assertNull($normalizer->normalize('нет данных'));
    }

    public function test_case_number_normalizer_keeps_meaningful_number_parts(): void
    {
        $normalizer = new CaseNumberNormalizer;

        $this->assertSame('2-100/2025', $normalizer->normalize(' № 2-100 / 2025 '));
        $this->assertSame('2-100/2025', $normalizer->firstInstanceNumber('2-100/2025 (2-50/2024;) ~ М-1/2025'));
    }

    public function test_category_event_and_result_normalizers(): void
    {
        $this->assertSame('insurance', (new CategoryNormalizer)->normalize('Споры, связанные со страхованием и ОСАГО'));
        $this->assertSame('case_received', (new EventTypeNormalizer)->normalize('Регистрация иска (заявления, жалобы) в суде'));
        $this->assertSame('hearing_postponed', (new EventTypeNormalizer)->normalize('Судебное заседание', 'Отложено'));
        $this->assertSame('partially_satisfied', (new ResultNormalizer)->normalize('Иск удовлетворен частично'));
    }
}
