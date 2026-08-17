<?php

use App\Http\Controllers\Admin\CaseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ParserController;
use App\Http\Controllers\Admin\ParserSettingController;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => redirect()->route('admin.dashboard'))->name('home');
Route::get('/dashboard', fn (): RedirectResponse => redirect()->route('admin.dashboard'))->middleware(['auth', 'admin'])->name('dashboard');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('parser', [ParserController::class, 'index'])->name('parser.index');
    Route::post('parser/initial', [ParserController::class, 'startInitial'])->name('parser.initial.store');
    Route::post('parser/regular', [ParserController::class, 'startRegular'])->name('parser.regular.store');
    Route::post('parser/campaigns/{campaign}/pause', [ParserController::class, 'pause'])->name('parser.campaigns.pause');
    Route::post('parser/campaigns/{campaign}/resume', [ParserController::class, 'resume'])->name('parser.campaigns.resume');
    Route::post('parser/campaigns/{campaign}/finish', [ParserController::class, 'finish'])->name('parser.campaigns.finish');
    Route::delete('parser/campaigns/{campaign}', [ParserController::class, 'cancel'])->name('parser.campaigns.destroy');

    Route::get('cases', [CaseController::class, 'index'])->name('cases.index');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');

    Route::get('settings', [ParserSettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [ParserSettingController::class, 'update'])->name('settings.update');
});

require __DIR__.'/settings.php';
