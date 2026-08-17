<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminActivityLog;
use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\AvailabilityIncident;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\CourtCase;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\ParserRun;
use App\Models\Parser\SourceRuntimeState;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $activeCampaign = CrawlCampaign::query()
            ->whereIn('status', ['pending', 'running', 'paused'])
            ->withCount([
                'workItems as pending_work_count' => fn ($query) => $query->whereIn('status', ['pending', 'failed']),
                'workItems as completed_work_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->latest('id')
            ->first();

        return Inertia::render('admin/dashboard', [
            'summary' => [
                'courts' => Court::query()->where('is_enabled', true)->count(),
                'cases' => CourtCase::query()->count(),
                'training_cases' => CourtCase::query()->where('is_training_candidate', true)->count(),
                'case_instances' => CaseInstance::query()->count(),
                'open_incidents' => AvailabilityIncident::query()->whereIn('status', ['suspected', 'confirmed'])->count(),
            ],
            'source' => SourceRuntimeState::query()->where('source_type', 'sudrf')->first(),
            'activeCampaign' => $activeCampaign,
            'latestRun' => ParserRun::query()->latest('id')->first(),
            'availability' => AvailabilityCheck::query()
                ->with('court:id,name')
                ->latest('checked_at')
                ->limit(12)
                ->get(),
            'activities' => AdminActivityLog::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }
}
