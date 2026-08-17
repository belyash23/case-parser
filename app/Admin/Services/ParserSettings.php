<?php

namespace App\Admin\Services;

use App\Models\Parser\ParserSetting;

class ParserSettings
{
    public function current(): ParserSetting
    {
        return ParserSetting::current();
    }
}
