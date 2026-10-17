<?php

use App\Modules\Inquiries\Experience\Controllers\InquiryCaseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:customers.view'])
    ->get('/projects/{projectKey}/inquiries', [InquiryCaseController::class, 'index'])
    ->name('inquiries.index');

Route::middleware(['web', 'auth', 'can:customers.operate'])->group(function (): void {
    Route::post('/projects/{projectKey}/inquiries/{caseId}/assignment', [InquiryCaseController::class, 'assignment'])
        ->name('inquiries.assignment');
    Route::post('/projects/{projectKey}/inquiries/{caseId}/identity/company', [InquiryCaseController::class, 'linkCompany'])
        ->name('inquiries.identity.company');
});