<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class CaseNumberNormalizer
{
    public function normalize(?string $value): ?string
    {
        $value = Html::normalizeText($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = str_replace(['№', 'N'], '', $value);

        return mb_strtoupper($value);
    }

    public function firstInstanceNumber(?string $value): ?string
    {
        $value = Html::normalizeText($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\(.+?\)/u', '', $value) ?? $value;
        $value = explode('~', $value)[0] ?? $value;

        return $this->normalize($value);
    }
}
