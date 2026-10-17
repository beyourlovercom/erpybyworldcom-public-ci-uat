<?php

use App\Modules\BusinessProjects\Experience\Controllers\ProjectDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:dashboard.view'])
    ->get('/projects/{projectKey}', ProjectDashboardController::class)
    ->name('projects.show');