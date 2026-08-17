<?php

namespace App\Enums\Parser;

enum CrawlWorkType: string
{
    case CalendarDay = 'calendar_day';
    case InitialMonth = 'initial_month';
    case CaseCard = 'case_card';
    case HeadSync = 'head_sync';
    case BacklogDrain = 'backlog_drain';
    case Recheck = 'recheck';
}
