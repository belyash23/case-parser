<?php

namespace App\Admin\Actions;

use App\Models\Parser\ParserSetting;

class UpdateParserSettings
{
    /** @param array<string, mixed> $settings */
    public function execute(array $settings): ParserSetting
    {
        $parserSetting = ParserSetting::current();
        $parserSetting->update($settings);

        return $parserSetting->refresh();
    }
}
