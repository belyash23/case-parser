<?php

namespace App\Parser\DTO;

use App\Enums\Parser\RegularCrawlStopReason;

final readonly class RegularCrawlResult
{
    public function __construct(
        public RegularCrawlStopReason $reason,
        public int $steps,
        public int $requests,
    ) {}
}
