<?php

use App\Enums\Parser\CrawlCampaignMode;
use App\Models\Parser\ParserSetting;
use App\Models\Parser\SourceRuntimeState;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitor:sudrf --scheduled')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

Schedule::command('parser:crawl-initial --resume --scheduled')
    ->everyMinute()
    ->when(function (): bool {
        $campaign = SourceRuntimeState::query()
            ->where('source_type', 'sudrf')
            ->with('activeCampaign')
            ->first()
            ?->activeCampaign;

        return $campaign !== null
            && $campaign->mode === CrawlCampaignMode::Initial
            && ! $campaign->status->isTerminal()
            && ($campaign->settings_json['auto_resume'] ?? true);
    })
    ->withoutOverlapping(3)
    ->onOneServer()
    ->runInBackground();

Schedule::command('parser:crawl-regular --scheduled')
    ->everyMinute()
    ->when(fn (): bool => ParserSetting::current()->regular_scheduling_enabled)
    ->withoutOverlapping(3)
    ->onOneServer()
    ->runInBackground();

Schedule::command('admin:reports-prune')
    ->dailyAt('02:15')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('database:backup')
    ->dailyAt('03:00')
    ->when(fn (): bool => (bool) config('parser.operations.backup_enabled'))
    ->withoutOverlapping(120)
    ->onOneServer()
    ->runInBackground();

Schedule::command('parser:prune-operational-data')
    ->dailyAt('03:30')
    ->when(fn (): bool => (bool) config('parser.operations.pruning_enabled'))
    ->withoutOverlapping(120)
    ->onOneServer()
    ->runInBackground();

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->onOneServer();
