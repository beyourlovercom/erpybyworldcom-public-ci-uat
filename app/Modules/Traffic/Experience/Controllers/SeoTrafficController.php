<?php

namespace App\Modules\Traffic\Experience\Controllers;

use App\Modules\Traffic\Application\SeoGrowthEngineService;
use App\Modules\Traffic\Application\SeoTrafficWorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class SeoTrafficController
{
    public function __construct(
        private readonly SeoTrafficWorkspaceService $workspace,
        private readonly SeoGrowthEngineService $growthEngine,
    ) {}

    public function __invoke(Request $request, string $projectKey): Response
    {
        try {
            $payload = $this->workspace->build($projectKey, $request->only(['search', 'action', 'source']));
            $payload['growth'] = $this->growthEngine->build($projectKey);
        } catch (InvalidArgumentException) {
            abort(404, 'Business project not found.');
        }

        return Inertia::render('Traffic/Seo/Index', $payload);
    }
}
