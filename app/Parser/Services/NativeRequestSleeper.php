<?php

namespace App\Parser\Services;

use App\Parser\Contracts\RequestSleeper;

class NativeRequestSleeper implements RequestSleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
