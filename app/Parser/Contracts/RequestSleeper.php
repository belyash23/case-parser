<?php

namespace App\Parser\Contracts;

interface RequestSleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
