<?php

use App\Modules\Content\Experience\Controllers\ContentWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:content.view'])->group(function (): void {
    Route::get('/content', [ContentWorkspaceController::class, 'index'])->name('content.index');
    Route::get('/content/articles/{article}', [ContentWorkspaceController::class, 'show'])->name('content.show');
});

Route::middleware(['web', 'auth', 'can:content.write'])->group(function (): void {
    Route::post('/content/claim-next', [ContentWorkspaceController::class, 'claimNext'])->name('content.claim-next');
    Route::post('/content/articles/{article}/start', [ContentWorkspaceController::class, 'startWriting'])->name('content.start-writing');
    Route::post('/content/articles/{article}/release', [ContentWorkspaceController::class, 'release'])->name('content.release');
    Route::post('/content/articles/{article}/approvals/writer', [ContentWorkspaceController::class, 'approveWriter'])->name('content.approvals.writer');
    Route::post('/content/articles/{article}/document', [ContentWorkspaceController::class, 'submitDocument'])->name('content.document.submit');
    Route::post('/content/articles/{article}/document/check', [ContentWorkspaceController::class, 'checkDocument'])->name('content.document.check');
    Route::post('/content/articles/{article}/document/reimport', [ContentWorkspaceController::class, 'reimportDocument'])->name('content.document.reimport');
});

Route::middleware(['web', 'auth', 'can:content.review'])->group(function (): void {
    Route::post('/content/articles/{article}/editorial', [ContentWorkspaceController::class, 'runEditorial'])->name('content.editorial.run');
    Route::post('/content/articles/{article}/approvals/fact', [ContentWorkspaceController::class, 'approveFact'])->name('content.approvals.fact');
});

Route::middleware(['web', 'auth', 'can:content.publish'])->group(function (): void {
    Route::post('/content/articles/{article}/preview', [ContentWorkspaceController::class, 'preparePreview'])->name('content.preview.prepare');
    Route::post('/content/articles/{article}/verification/resolve', [ContentWorkspaceController::class, 'resolvePublicationVerification'])->name('content.verification.resolve');
    Route::post('/content/articles/{article}/owner-uat', [ContentWorkspaceController::class, 'submitOwnerUat'])->name('content.owner-uat.submit');
    Route::post('/content/articles/{article}/approvals/seo', [ContentWorkspaceController::class, 'approveSeo'])->name('content.approvals.seo');
    Route::post('/content/articles/{article}/publish', [ContentWorkspaceController::class, 'publish'])->name('content.publish');
});

Route::middleware(['web', 'auth', 'can:content.manage'])->group(function (): void {
    Route::post('/content/sources/seo-backlog/{backlog}', [ContentWorkspaceController::class, 'createFromSource'])->name('content.sources.seo-backlog.create');
    Route::post('/content/writer-scopes', [ContentWorkspaceController::class, 'addScope'])->name('content.writer-scopes.store');
    Route::delete('/content/writer-scopes/{scope}', [ContentWorkspaceController::class, 'removeScope'])->name('content.writer-scopes.destroy');
});
