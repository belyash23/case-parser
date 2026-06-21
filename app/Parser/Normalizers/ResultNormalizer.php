<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class ResultNormalizer
{
    public function normalize(?string $value): ?string
    {
        $value = mb_strtolower(Html::normalizeText($value));

        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'удовлетвор') && str_contains($value, 'частич') => 'partially_satisfied',
            str_contains($value, 'удовлетвор') => 'satisfied',
            str_contains($value, 'отказ') => 'denied',
            str_contains($value, 'прекращ') => 'terminated',
            str_contains($value, 'оставлен без рассмотрения') => 'left_without_consideration',
            str_contains($value, 'возвращ') => 'returned',
            str_contains($value, 'назначен') => 'scheduled',
            str_contains($value, 'отлож') => 'postponed',
            default => 'other',
        };
    }
}
