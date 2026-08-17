<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateParserSettings;
use App\Admin\Services\AdminActivityRecorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateParserSettingsRequest;
use App\Models\Parser\ParserSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ParserSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings', [
            'settings' => ParserSetting::current(),
            'limits' => [
                'minimum_request_interval_ms' => 1000,
                'recommended_request_interval_ms' => 10000,
            ],
        ]);
    }

    public function update(UpdateParserSettingsRequest $request, UpdateParserSettings $updateSettings, AdminActivityRecorder $activity): RedirectResponse
    {
        $settings = $updateSettings->execute($request->validated());
        $activity->record($request->user(), 'settings.updated', $settings, $request->validated(), $request->ip());

        return back()->with('success', 'Настройки сохранены и применятся к следующему запросу.');
    }
}
