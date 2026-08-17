<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\ControlCrawlCampaign;
use App\Admin\Services\AdminActivityRecorder;
use App\Enums\Parser\CrawlCampaignMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartInitialCrawlRequest;
use App\Http\Requests\Admin\StartRegularCrawlRequest;
use App\Jobs\Admin\RunParserJob;
use App\Models\Parser\Court;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\CrawlWorkItem;
use App\Models\Parser\ParserSetting;
use App\Models\Parser\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/parser', [
            'campaigns' => CrawlCampaign::query()
                ->withCount([
                    'workItems as pending_work_count' => fn ($query) => $query->whereIn('status', ['pending', 'failed']),
                    'workItems as running_work_count' => fn ($query) => $query->where('status', 'running'),
                    'workItems as completed_work_count' => fn ($query) => $query->where('status', 'completed'),
                ])
                ->latest('id')
                ->limit(20)
                ->get(),
            'workItems' => CrawlWorkItem::query()
                ->with('court:id,name')
                ->whereIn('status', ['running', 'failed', 'pending'])
                ->orderByRaw("case when status = 'running' then 0 when status = 'failed' then 1 else 2 end")
                ->latest('updated_at')
                ->limit(30)
                ->get(),
            'regions' => Region::query()->enabled()->orderBy('name')->get(['id', 'sudrf_region_id', 'name']),
            'courts' => Court::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'region_id', 'name']),
        ]);
    }

    public function startInitial(StartInitialCrawlRequest $request, AdminActivityRecorder $activity): RedirectResponse
    {
        $data = $request->validated();
        RunParserJob::dispatch('initial', $this->newCampaignArguments($data, true))->afterCommit();
        $activity->record($request->user(), 'parser.initial_queued', context: $data, ipAddress: $request->ip());

        return back()->with('success', 'Первоначальный обход поставлен в очередь.');
    }

    public function startRegular(StartRegularCrawlRequest $request, AdminActivityRecorder $activity): RedirectResponse
    {
        $data = $request->validated();
        ParserSetting::current()->update(['regular_scheduling_enabled' => true]);
        RunParserJob::dispatch('regular', $this->newCampaignArguments($data, false))->afterCommit();
        $activity->record($request->user(), 'parser.regular_queued', context: $data, ipAddress: $request->ip());

        return back()->with('success', 'Регулярный обход поставлен в очередь.');
    }

    public function pause(Request $request, CrawlCampaign $campaign, ControlCrawlCampaign $control, AdminActivityRecorder $activity): RedirectResponse
    {
        $control->pause($campaign);

        if ($campaign->mode === CrawlCampaignMode::Regular) {
            ParserSetting::current()->update(['regular_scheduling_enabled' => false]);
        }

        $activity->record($request->user(), 'parser.paused', $campaign, ipAddress: $request->ip());

        return back()->with('success', 'Остановка запрошена. Новый сетевой запрос не начнётся.');
    }

    public function resume(Request $request, CrawlCampaign $campaign, ControlCrawlCampaign $control, AdminActivityRecorder $activity): RedirectResponse
    {
        $control->resume($campaign);

        if ($campaign->mode === CrawlCampaignMode::Regular) {
            ParserSetting::current()->update(['regular_scheduling_enabled' => true]);
        }

        RunParserJob::dispatch($campaign->mode->value, ['--campaign' => $campaign->id])->afterCommit();
        $activity->record($request->user(), 'parser.resumed', $campaign, ipAddress: $request->ip());

        return back()->with('success', 'Кампания поставлена на продолжение.');
    }

    public function finish(Request $request, CrawlCampaign $campaign, ControlCrawlCampaign $control, AdminActivityRecorder $activity): RedirectResponse
    {
        $control->finish($campaign);
        $activity->record($request->user(), 'parser.finished_early', $campaign, ipAddress: $request->ip());

        return back()->with('success', 'Кампания завершена досрочно, накопленные данные сохранены.');
    }

    public function cancel(Request $request, CrawlCampaign $campaign, ControlCrawlCampaign $control, AdminActivityRecorder $activity): RedirectResponse
    {
        $control->cancel($campaign);
        $activity->record($request->user(), 'parser.cancelled', $campaign, ipAddress: $request->ip());

        return back()->with('success', 'Кампания отменена. Накопленные данные не удалялись.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function newCampaignArguments(array $data, bool $includeDates): array
    {
        $arguments = [];

        if ($includeDates) {
            $arguments['--from'] = $data['from'];
            $arguments['--to'] = $data['to'];
        }

        if (($data['court_ids'] ?? []) !== []) {
            $arguments['--court'] = $data['court_ids'];
        }

        if (($data['region_ids'] ?? []) !== []) {
            $arguments['--region'] = $data['region_ids'];
        }

        if ((bool) ($data['skip_directory_sync'] ?? false)) {
            $arguments['--skip-directory-sync'] = true;
        }

        return $arguments;
    }
}
