<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class ResultNormalizer
{
    private const RU_SATISFIED = "\u{0443}\u{0434}\u{043e}\u{0432}\u{043b}\u{0435}\u{0442}\u{0432}\u{043e}\u{0440}";

    private const RU_PARTIAL = "\u{0447}\u{0430}\u{0441}\u{0442}";

    private const RU_DENIED = "\u{043e}\u{0442}\u{043a}\u{0430}\u{0437}";

    private const RU_TERMINATED = "\u{043f}\u{0440}\u{0435}\u{043a}\u{0440}\u{0430}\u{0449}";

    private const RU_LEFT_WITHOUT_CONSIDERATION = "\u{043e}\u{0441}\u{0442}\u{0430}\u{0432}\u{043b}\u{0435}\u{043d} \u{0431}\u{0435}\u{0437} \u{0440}\u{0430}\u{0441}\u{0441}\u{043c}\u{043e}\u{0442}\u{0440}\u{0435}\u{043d}\u{0438}\u{044f}";

    private const RU_RETURNED = "\u{0432}\u{043e}\u{0437}\u{0432}\u{0440}\u{0430}\u{0449}";

    private const RU_SCHEDULED = "\u{043d}\u{0430}\u{0437}\u{043d}\u{0430}\u{0447}";

    private const RU_POSTPONED = "\u{043e}\u{0442}\u{043b}\u{043e}\u{0436}";

    private const RU_JURISDICTION = "\u{043f}\u{043e}\u{0434}\u{0441}\u{0443}\u{0434}\u{043d}\u{043e}\u{0441}\u{0442}";

    private const RU_COMPETENCE = "\u{043f}\u{043e}\u{0434}\u{0432}\u{0435}\u{0434}\u{043e}\u{043c}\u{0441}\u{0442}\u{0432}\u{0435}\u{043d}";

    private const RU_TRANSFERRED = "\u{043f}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}\u{043d}";

    private const RU_ANOTHER = "\u{0434}\u{0440}\u{0443}\u{0433}";

    private const RU_COURT = "\u{0441}\u{0443}\u{0434}";

    private const RU_JOINED = "\u{043f}\u{0440}\u{0438}\u{0441}\u{043e}\u{0435}\u{0434}\u{0438}\u{043d}";

    private const RU_MERGED = "\u{0441}\u{043e}\u{0435}\u{0434}\u{0438}\u{043d}";

    private const RU_CASE = "\u{0434}\u{0435}\u{043b}";

    public function normalize(?string $value): ?string
    {
        $value = mb_strtolower(Html::normalizeText($value));

        if ($value === '') {
            return null;
        }

        return match (true) {
            $this->isJoinedToAnotherCase($value) => 'joined_to_another_case',
            $this->isTransferByJurisdiction($value) => 'transferred_by_jurisdiction',
            str_contains($value, self::RU_SATISFIED) && str_contains($value, self::RU_PARTIAL) => 'partially_satisfied',
            str_contains($value, self::RU_SATISFIED) => 'satisfied',
            str_contains($value, self::RU_DENIED) => 'denied',
            str_contains($value, self::RU_TERMINATED) => 'terminated',
            str_contains($value, self::RU_LEFT_WITHOUT_CONSIDERATION) => 'left_without_consideration',
            str_contains($value, self::RU_RETURNED) => 'returned',
            str_contains($value, self::RU_SCHEDULED) => 'scheduled',
            str_contains($value, self::RU_POSTPONED) => 'postponed',
            default => 'other',
        };
    }

    private function isJoinedToAnotherCase(string $value): bool
    {
        if (str_contains($value, self::RU_JOINED) && str_contains($value, self::RU_CASE)) {
            return true;
        }

        return str_contains($value, self::RU_MERGED)
            && str_contains($value, self::RU_ANOTHER)
            && str_contains($value, self::RU_CASE);
    }

    private function isTransferByJurisdiction(string $value): bool
    {
        if (str_contains($value, self::RU_JURISDICTION) || str_contains($value, self::RU_COMPETENCE)) {
            return true;
        }

        return str_contains($value, self::RU_TRANSFERRED)
            && str_contains($value, self::RU_ANOTHER)
            && str_contains($value, self::RU_COURT);
    }
}
