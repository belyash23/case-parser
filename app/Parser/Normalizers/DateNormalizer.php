<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;
use Carbon\CarbonImmutable;
use Throwable;

class DateNormalizer
{
    public function normalize(?string $value): ?CarbonImmutable
    {
        $value = Html::normalizeText($value);

        if ($value === '' || in_array(mb_strtolower($value), ['-', 'нет данных', 'не указано'], true)) {
            return null;
        }

        if (! preg_match('/(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?/u', $value, $matches)) {
            return null;
        }

        $format = isset($matches[2]) ? 'd.m.Y H:i' : 'd.m.Y';
        $date = $matches[1].(isset($matches[2]) ? ' '.$matches[2] : '');

        try {
            return CarbonImmutable::createFromFormat($format, $date) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
