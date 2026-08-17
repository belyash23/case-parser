<?php

namespace Tests\Fakes;

use App\Parser\Contracts\RequestSleeper;
use Carbon\CarbonImmutable;

class AdvancingRequestSleeper implements RequestSleeper
{
    /** @var array<int, int> */
    public array $sleeps = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds($milliseconds));
    }
}
