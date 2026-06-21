<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class CategoryNormalizer
{
    public function normalize(?string $value): ?string
    {
        $value = Html::normalizeText($value);

        if ($value === '') {
            return null;
        }

        $top = trim(preg_split('/→|->|&rarr;/u', $value)[0] ?? $value);
        $lower = mb_strtolower($top);

        return match (true) {
            str_contains($lower, 'страх') => 'insurance',
            str_contains($lower, 'жилищ') => 'housing',
            str_contains($lower, 'семейн') => 'family',
            str_contains($lower, 'труд') => 'labor',
            str_contains($lower, 'кредит') || str_contains($lower, 'займ') => 'credit',
            str_contains($lower, 'ущерб') || str_contains($lower, 'вред') => 'damages',
            str_contains($lower, 'административ') => 'administrative',
            default => mb_substr($lower, 0, 120),
        };
    }
}
