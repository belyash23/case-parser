<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Services\AdminActivityRecorder;
use App\Enums\Admin\ReportStatus;
use App\Enums\Admin\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReportRequest;
use App\Jobs\Admin\GenerateAdminReportJob;
use App\Models\Admin\AdminReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/reports', [
            'reports' => AdminReport::query()->latest('id')->paginate(25),
            'reportTypes' => collect(ReportType::cases())->map(fn (ReportType $type) => $type->value)->all(),
        ]);
    }

    public function store(StoreReportRequest $request, AdminActivityRecorder $activity): RedirectResponse
    {
        $data = $request->validated();
        $type = ReportType::from($data['type']);
        $format = $type === ReportType::CaseInspection ? 'json' : $data['format'];
        $report = AdminReport::query()->create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'format' => $format,
            'status' => ReportStatus::Queued,
            'filters_json' => collect($data)->except(['type'])->all(),
            'expires_at' => now()->addDays(7),
        ]);

        GenerateAdminReportJob::dispatch($report->id)->afterCommit();
        $activity->record($request->user(), 'report.queued', $report, $data, $request->ip());

        return back()->with('success', 'Отчёт поставлен в очередь.');
    }

    public function download(Request $request, AdminReport $report, AdminActivityRecorder $activity): StreamedResponse
    {
        abort_unless($report->status === ReportStatus::Ready && $report->path !== null, 404);
        abort_unless(Storage::disk('local')->exists($report->path), 404);
        $activity->record($request->user(), 'report.downloaded', $report, ipAddress: $request->ip());

        return Storage::disk('local')->download($report->path);
    }
}
