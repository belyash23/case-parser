<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class CategoryNormalizer
{
    private const RU_PROTECTION = "\u{0437}\u{0430}\u{0449}\u{0438}\u{0442}";

    private const RU_CONSUMER = "\u{043f}\u{043e}\u{0442}\u{0440}\u{0435}\u{0431}\u{0438}\u{0442}\u{0435}\u{043b}";

    private const RU_INSURANCE = "\u{0441}\u{0442}\u{0440}\u{0430}\u{0445}";

    private const RU_HOUSING = "\u{0436}\u{0438}\u{043b}\u{0438}\u{0449}";

    private const RU_FAMILY = "\u{0441}\u{0435}\u{043c}\u{0435}\u{0439}";

    private const RU_LABOR = "\u{0442}\u{0440}\u{0443}\u{0434}";

    private const RU_CREDIT = "\u{043a}\u{0440}\u{0435}\u{0434}\u{0438}\u{0442}";

    private const RU_LOAN = "\u{0437}\u{0430}\u{0439}\u{043c}";

    private const RU_DAMAGE = "\u{0432}\u{0440}\u{0435}\u{0434}";

    private const RU_LOSS = "\u{0443}\u{0449}\u{0435}\u{0440}\u{0431}";

    private const RU_ADMINISTRATIVE = "\u{0430}\u{0434}\u{043c}\u{0438}\u{043d}\u{0438}\u{0441}\u{0442}\u{0440}";

    public function normalize(?string $value): ?string
    {
        $path = $this->path($value);
        $top = $path[0] ?? null;

        if ($top === null || $top === '') {
            return null;
        }

        $lower = mb_strtolower($top);

        return match (true) {
            str_contains($lower, self::RU_PROTECTION) && str_contains($lower, self::RU_CONSUMER) => 'consumer_protection',
            str_contains($lower, self::RU_INSURANCE) => 'insurance',
            str_contains($lower, self::RU_HOUSING) => 'housing',
            str_contains($lower, self::RU_FAMILY) => 'family',
            str_contains($lower, self::RU_LABOR) => 'labor',
            str_contains($lower, self::RU_CREDIT) || str_contains($lower, self::RU_LOAN) => 'credit',
            str_contains($lower, self::RU_DAMAGE) || str_contains($lower, self::RU_LOSS) => 'damages',
            str_contains($lower, self::RU_ADMINISTRATIVE) => 'administrative',
            default => mb_substr($lower, 0, 120),
        };
    }

    /** @return array<int, string> */
    public function path(?string $value): array
    {
        $value = Html::normalizeText($value);

        if ($value === '') {
            return [];
        }

        return collect(preg_split('/\x{2192}|->|&rarr;/u', $value) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter(fn (string $part): bool => $part !== '')
            ->values()
            ->all();
    }
}
