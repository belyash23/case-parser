<?php

namespace App\Enums\Parser;

enum SourceCircuitStatus: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
