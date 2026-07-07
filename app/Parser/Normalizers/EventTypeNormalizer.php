<?php

namespace App\Parser\Normalizers;

use App\Parser\Support\Html;

class EventTypeNormalizer
{
    private const RU_REGISTERED = "\u{0437}\u{0430}\u{0440}\u{0435}\u{0433}\u{0438}\u{0441}\u{0442}\u{0440}";

    private const RU_ACCEPTED_TO_PROCEEDING = "\u{043f}\u{0440}\u{0438}\u{043d}\u{044f}\u{0442}\u{043e} \u{043a} \u{043f}\u{0440}\u{043e}\u{0438}\u{0437}\u{0432}\u{043e}\u{0434}\u{0441}\u{0442}\u{0432}\u{0443}";

    private const RU_ACCEPT_TO_PROCEEDING = "\u{043f}\u{0440}\u{0438}\u{043d}\u{044f}\u{0442}\u{044c} \u{043a} \u{043f}\u{0440}\u{043e}\u{0438}\u{0437}\u{0432}\u{043e}\u{0434}\u{0441}\u{0442}\u{0432}\u{0443}";

    private const RU_CASE_OPENED = "\u{0432}\u{043e}\u{0437}\u{0431}\u{0443}\u{0436}\u{0434}\u{0435}\u{043d}\u{043e} \u{0434}\u{0435}\u{043b}\u{043e}";

    private const RU_CONVERSATION_TO_PROCEEDING = "\u{0431}\u{0435}\u{0441}\u{0435}\u{0434}\u{0430} \u{043a} \u{043f}\u{0440}\u{043e}\u{0438}\u{0437}\u{0432}\u{043e}\u{0434}\u{0441}\u{0442}\u{0432}\u{0443}";

    private const RU_SENT_TO_JUDGE = "\u{043f}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}\u{043d}\u{043e} \u{0441}\u{0443}\u{0434}\u{044c}\u{0435}";

    private const RU_LEFT_WITHOUT = "\u{043e}\u{0441}\u{0442}\u{0430}\u{0432}\u{043b}\u{0435}\u{043d}";

    private const RU_MOVEMENT = "\u{0434}\u{0432}\u{0438}\u{0436}";

    private const RU_RETURNED = "\u{0432}\u{043e}\u{0437}\u{0432}\u{0440}\u{0430}\u{0449}";

    private const RU_POSTPONED = "\u{043e}\u{0442}\u{043b}\u{043e}\u{0436}";

    private const RU_EXPERTISE = "\u{044d}\u{043a}\u{0441}\u{043f}\u{0435}\u{0440}\u{0442}\u{0438}\u{0437}";

    private const RU_SUSPENDED = "\u{043f}\u{0440}\u{0438}\u{043e}\u{0441}\u{0442}\u{0430}\u{043d}\u{043e}\u{0432}";

    private const RU_RESUMED = "\u{0432}\u{043e}\u{0437}\u{043e}\u{0431}\u{043d}\u{043e}\u{0432}";

    private const RU_MOTIVATED = "\u{043c}\u{043e}\u{0442}\u{0438}\u{0432}\u{0438}\u{0440}\u{043e}\u{0432}\u{0430}\u{043d}";

    private const RU_DECISION = "\u{0440}\u{0435}\u{0448}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_RULING = "\u{043e}\u{043f}\u{0440}\u{0435}\u{0434}\u{0435}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_RESOLUTION = "\u{043f}\u{043e}\u{0441}\u{0442}\u{0430}\u{043d}\u{043e}\u{0432}\u{043b}\u{0435}\u{043d}\u{0438}\u{0435}";

    private const RU_ISSUED = "\u{0432}\u{044b}\u{043d}\u{0435}\u{0441}\u{0435}\u{043d}";

    private const RU_CONSIDERED = "\u{0440}\u{0430}\u{0441}\u{0441}\u{043c}\u{043e}\u{0442}\u{0440}\u{0435}\u{043d}";

    private const RU_APPEAL = "\u{0430}\u{043f}\u{0435}\u{043b}\u{043b}\u{044f}\u{0446}\u{0438}\u{043e}\u{043d}";

    private const RU_CASSATION = "\u{043a}\u{0430}\u{0441}\u{0441}\u{0430}\u{0446}\u{0438}\u{043e}\u{043d}";

    private const RU_COMPLAINT = "\u{0436}\u{0430}\u{043b}\u{043e}\u{0431}";

    private const RU_SENT = "\u{043d}\u{0430}\u{043f}\u{0440}\u{0430}\u{0432}";

    private const RU_WRIT = "\u{0438}\u{0441}\u{043f}\u{043e}\u{043b}\u{043d}\u{0438}\u{0442}\u{0435}\u{043b}\u{044c}\u{043d}";

    private const RU_HEARING = "\u{0441}\u{0443}\u{0434}\u{0435}\u{0431}\u{043d}\u{043e}\u{0435} \u{0437}\u{0430}\u{0441}\u{0435}\u{0434}\u{0430}\u{043d}\u{0438}\u{0435}";

    private const RU_PRELIMINARY_HEARING = "\u{043f}\u{0440}\u{0435}\u{0434}\u{0432}\u{0430}\u{0440}\u{0438}\u{0442}\u{0435}\u{043b}\u{044c}\u{043d}\u{043e}\u{0435} \u{0441}\u{0443}\u{0434}\u{0435}\u{0431}\u{043d}\u{043e}\u{0435} \u{0437}\u{0430}\u{0441}\u{0435}\u{0434}\u{0430}\u{043d}\u{0438}\u{0435}";

    private const RU_TRANSFERRED = "\u{043f}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}\u{043d}";

    private const RU_ANOTHER = "\u{0434}\u{0440}\u{0443}\u{0433}";

    private const RU_COURT = "\u{0441}\u{0443}\u{0434}";

    public function normalize(?string $eventName, ?string $eventResult = null): string
    {
        $text = mb_strtolower(Html::normalizeText(($eventName ?? '').' '.($eventResult ?? '')));

        return match (true) {
            $this->isTransferToAnotherCourt($text) => 'case_transferred_to_another_court',
            str_contains($text, self::RU_REGISTERED) => 'case_received',
            str_contains($text, self::RU_ACCEPTED_TO_PROCEEDING) => 'case_accepted',
            str_contains($text, self::RU_ACCEPT_TO_PROCEEDING) || str_contains($text, self::RU_CASE_OPENED) => 'case_accepted',
            str_contains($text, self::RU_CONVERSATION_TO_PROCEEDING) || str_contains($text, self::RU_SENT_TO_JUDGE) => 'case_accepted',
            str_contains($text, self::RU_LEFT_WITHOUT) && str_contains($text, self::RU_MOVEMENT) => 'left_without_movement',
            str_contains($text, self::RU_RETURNED) => 'returned',
            str_contains($text, self::RU_POSTPONED) => 'hearing_postponed',
            str_contains($text, self::RU_EXPERTISE) => 'expertise_ordered',
            str_contains($text, self::RU_SUSPENDED) => 'proceeding_suspended',
            str_contains($text, self::RU_RESUMED) => 'proceeding_resumed',
            str_contains($text, self::RU_MOTIVATED) => 'motivated_decision_prepared',
            str_contains($text, self::RU_DECISION) || str_contains($text, self::RU_RULING) || str_contains($text, self::RU_RESOLUTION) => 'decision_issued',
            str_contains($text, self::RU_ISSUED) || str_contains($text, self::RU_CONSIDERED) => 'decision_issued',
            str_contains($text, self::RU_APPEAL) && str_contains($text, self::RU_COMPLAINT) => 'appeal_filed',
            str_contains($text, self::RU_APPEAL) && str_contains($text, self::RU_SENT) => 'case_sent_to_appeal',
            str_contains($text, self::RU_CASSATION) && str_contains($text, self::RU_COMPLAINT) => 'cassation_filed',
            str_contains($text, self::RU_WRIT) => 'writ_issued',
            str_contains($text, self::RU_HEARING) || str_contains($text, self::RU_PRELIMINARY_HEARING) => 'hearing_scheduled',
            default => 'unknown',
        };
    }

    private function isTransferToAnotherCourt(string $text): bool
    {
        return str_contains($text, self::RU_TRANSFERRED)
            && str_contains($text, self::RU_ANOTHER)
            && str_contains($text, self::RU_COURT);
    }
}
