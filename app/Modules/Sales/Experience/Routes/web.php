<?php

use App\Modules\Sales\Experience\Controllers\SalesOpportunityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:sales.view'])->group(function (): void {
    Route::get('/projects/{projectKey}/sales', [SalesOpportunityController::class, 'index'])->name('sales.index');
    Route::get('/projects/{projectKey}/sales/receipts', [SalesOpportunityController::class, 'receipts'])->name('sales.receipts.index');
    Route::get('/projects/{projectKey}/sales/opportunities/{opportunity}', [SalesOpportunityController::class, 'show'])->name('sales.opportunities.show');
    Route::get('/projects/{projectKey}/sales/opportunities/{opportunity}/follow-ups/{followUp}/attachments/{attachment}', [SalesOpportunityController::class, 'followUpAttachment'])->name('sales.opportunities.follow-ups.attachments.show');

    Route::middleware('can:sales.operate')->group(function (): void {
        Route::post('/projects/{projectKey}/sales/opportunities', [SalesOpportunityController::class, 'store'])->name('sales.opportunities.store');
        Route::patch('/projects/{projectKey}/sales/opportunities/{opportunity}/stage', [SalesOpportunityController::class, 'stage'])->name('sales.opportunities.stage');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/follow-ups', [SalesOpportunityController::class, 'followUp'])->name('sales.opportunities.follow-ups.store');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/quotes', [SalesOpportunityController::class, 'quote'])->name('sales.opportunities.quotes.store');
        Route::patch('/projects/{projectKey}/sales/opportunities/{opportunity}/receivable', [SalesOpportunityController::class, 'receivable'])->name('sales.opportunities.receivable.update');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/payment-receipts', [SalesOpportunityController::class, 'paymentReceipt'])->name('sales.opportunities.payment-receipts.store');
        Route::patch('/projects/{projectKey}/sales/opportunities/{opportunity}/payment-receipts/{receipt}/void', [SalesOpportunityController::class, 'voidPaymentReceipt'])->name('sales.opportunities.payment-receipts.void');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/won', [SalesOpportunityController::class, 'won'])->name('sales.opportunities.won');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/lost', [SalesOpportunityController::class, 'lost'])->name('sales.opportunities.lost');
        Route::post('/projects/{projectKey}/sales/opportunities/{opportunity}/reopen', [SalesOpportunityController::class, 'reopen'])->name('sales.opportunities.reopen');
    });
});
