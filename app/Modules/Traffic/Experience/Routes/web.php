<?php

use App\Modules\Traffic\Experience\Controllers\BossDashboardController;
use App\Modules\Traffic\Experience\Controllers\Ga4TrafficDetailController;
use App\Modules\Traffic\Experience\Controllers\LandingPagePerformanceController;
use App\Modules\Traffic\Experience\Controllers\SeoTrafficController;
use App\Modules\Traffic\Experience\Controllers\TrafficOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:marketing.view'])->group(function (): void {
    Route::get('/marketing/traffic', BossDashboardController::class)->name('traffic.boss-dashboard');
    Route::get('/projects/{projectKey}/growth', BossDashboardController::class)->name('growth.project-dashboard');
    Route::get('/projects/{projectKey}/traffic', TrafficOverviewController::class)->name('traffic.project-dashboard');
    Route::get('/projects/{projectKey}/traffic/ga4', Ga4TrafficDetailController::class)->name('traffic.project-ga4');
    Route::get('/projects/{projectKey}/traffic/landing-pages', LandingPagePerformanceController::class)->name('traffic.project-landing-pages');
    Route::get('/projects/{projectKey}/traffic/seo', SeoTrafficController::class)->name('traffic.project-seo');
});
