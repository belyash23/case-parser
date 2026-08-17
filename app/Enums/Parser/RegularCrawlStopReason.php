<?php

namespace App\Enums\Parser;

enum RegularCrawlStopReason: string
{
    case TimeLimit = 'time_limit';
    case RequestLimit = 'request_limit';
    case BudgetExhausted = 'budget_exhausted';
    case Idle = 'idle';
    case Paused = 'paused';
}
