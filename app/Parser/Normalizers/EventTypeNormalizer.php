<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class EventTypeNormalizer
{
    public function normalize(?string $eventName, ?string $eventResult = null): string
    {
        $text = mb_strtolower(Html::normalizeText(($eventName ?? '').' '.($eventResult ?? '')));

        return match (true) {
            str_contains($text, 'регистрация') => 'case_received',
            str_contains($text, 'принято к производству суда') => 'case_accepted',
            str_contains($text, 'беседа к производству') || str_contains($text, 'передано судье') => 'case_accepted',
            str_contains($text, 'оставлен') && str_contains($text, 'движ') => 'left_without_movement',
            str_contains($text, 'возвращ') => 'returned',
            str_contains($text, 'отлож') => 'hearing_postponed',
            str_contains($text, 'экспертиз') => 'expertise_ordered',
            str_contains($text, 'приостанов') => 'proceeding_suspended',
            str_contains($text, 'возобнов') => 'proceeding_resumed',
            str_contains($text, 'мотивирован') => 'motivated_decision_prepared',
            str_contains($text, 'решение') || str_contains($text, 'вынесено') || str_contains($text, 'рассмотрен') => 'decision_issued',
            str_contains($text, 'апелляцион') && str_contains($text, 'жалоб') => 'appeal_filed',
            str_contains($text, 'апелляцион') && str_contains($text, 'инстанц') => 'case_sent_to_appeal',
            str_contains($text, 'кассацион') && str_contains($text, 'жалоб') => 'cassation_filed',
            str_contains($text, 'исполнительн') => 'writ_issued',
            str_contains($text, 'судебное заседание') || str_contains($text, 'предварительное судебное заседание') => 'hearing_scheduled',
            default => 'unknown',
        };
    }
}
