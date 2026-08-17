<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CaseIndexRequest;
use App\Models\Parser\CaseInstance;
use App\Models\Parser\Court;
use App\Models\Parser\Region;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function index(CaseIndexRequest $request): Response
    {
        $filters = $request->validated();
        $instances = CaseInstance::query()
            ->select([
                'id', 'case_id', 'court_id', 'external_case_number', 'case_type', 'category_normalized',
                'court_instance_status_normalized', 'dispute_status_normalized', 'result_normalized',
                'started_at', 'completed_at', 'source_url',
            ])
            ->with(['court:id,name,region_id', 'courtCase:id,is_training_candidate,chain_status'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('external_case_number', 'like', '%'.$search.'%'))
            ->when($filters['court_id'] ?? null, fn ($query, $courtId) => $query->where('court_id', $courtId))
            ->when($filters['region_id'] ?? null, fn ($query, $regionId) => $query->whereHas('court', fn ($courtQuery) => $courtQuery->where('region_id', $regionId)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('dispute_status_normalized', $status))
            ->when((bool) ($filters['training_only'] ?? false), fn ($query) => $query->whereHas('courtCase', fn ($caseQuery) => $caseQuery->where('is_training_candidate', true)))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('started_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('started_at', '<=', $to))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $coverage = Court::query()
            ->select(['id', 'name'])
            ->where('is_enabled', true)
            ->withCount('caseInstances')
            ->withMin('caseInstances', 'started_at')
            ->withMax('caseInstances', 'started_at')
            ->orderByDesc('case_instances_count')
            ->limit(100)
            ->get();

        $monthlyCoverage = CaseInstance::query()
            ->selectRaw('court_id, substr(started_at, 1, 7) as month, count(*) as total')
            ->whereNotNull('started_at')
            ->groupBy('court_id', 'month')
            ->orderByDesc('month')
            ->limit(120)
            ->get();

        return Inertia::render('admin/cases', [
            'instances' => $instances,
            'filters' => $filters,
            'courts' => Court::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'name']),
            'regions' => Region::query()->enabled()->orderBy('name')->get(['id', 'name']),
            'coverage' => $coverage,
            'monthlyCoverage' => $monthlyCoverage,
        ]);
    }
}
