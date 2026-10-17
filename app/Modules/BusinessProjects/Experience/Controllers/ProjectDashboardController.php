<?php

namespace App\Modules\BusinessProjects\Experience\Controllers;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Traffic\Experience\Controllers\BossDashboardController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

final class ProjectDashboardController
{
    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly BossDashboardController $bossDashboard,
    ) {}

    public function __invoke(Request $request, string $projectKey): Response
    {
        $project = $this->projects->findByKey($projectKey);
        abort_if(! $project || $project->status !== 'active', 404, 'Business project not found.');

        if (Gate::allows('marketing.view')) {
            return ($this->bossDashboard)($request);
        }

        return Inertia::render('Projects/Show', [
            'project' => [
                'key' => $project->project_key,
                'name' => $project->name,
                'status' => $project->status,
            ],
            'sites' => $this->projects->listSites((int) $project->id)->map(fn ($site) => [
                'site_key' => $site->site_key,
                'domain' => $site->domain_normalized,
                'base_url' => $site->base_url,
                'is_primary' => (bool) $site->is_primary,
            ])->values()->all(),
        ]);
    }
}