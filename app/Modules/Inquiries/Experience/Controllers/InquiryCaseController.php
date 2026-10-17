<?php

namespace App\Modules\Inquiries\Experience\Controllers;

use App\Modules\Inquiries\Application\InquiryCaseService;
use App\Modules\Inquiries\Application\InquiryWorkflowService;
use App\Modules\Identity\Application\SalespersonOwnerScope;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class InquiryCaseController
{
    public function __construct(
        private readonly InquiryCaseService $cases,
        private readonly InquiryWorkflowService $workflow,
        private readonly SalespersonOwnerScope $ownerScope,
        private readonly BusinessProjectService $projects,
    ) {}

    public function index(Request $request, string $projectKey): Response
    {
        try {
            $payload = $this->cases->indexPayload($projectKey, $request->only(['status', 'stage', 'source']), $request->user());
        } catch (InvalidArgumentException) {
            abort(404, 'Business project not found.');
        }

        return Inertia::render('Inquiries/Index', $payload);
    }

    public function assignment(Request $request, string $projectKey, int $caseId): RedirectResponse
    {
        $this->ownerScope->assertInquiry($projectKey, $caseId, $request->user());
        $data = $request->validate(['owner_id' => ['nullable', 'integer', 'min:1']]);
        $ownerId = $this->ownerScope->ownerId($request->user());
        if ($ownerId !== null) {
            abort_if(($data['owner_id'] ?? null) === null || (int) $data['owner_id'] !== $ownerId, 403);
        }
        try {
            $this->workflow->assign($projectKey, $caseId, $data['owner_id'] ?? null, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['owner_id' => $exception->getMessage()]);
        }

        return back()->with('success', '询盘负责人已更新。');
    }

    public function linkCompany(Request $request, string $projectKey, int $caseId): RedirectResponse
    {
        $this->ownerScope->assertInquiry($projectKey, $caseId, $request->user());
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $ownerId = $this->ownerScope->ownerId($request->user());
        if ($ownerId !== null) {
            $project = $this->projects->findByKey($projectKey);
            abort_unless($project && BusinessProjectCompany::query()
                ->where('business_project_id', $project->id)
                ->where('company_id', (int) $data['company_id'])
                ->where('owner_id', $ownerId)->exists(), 403);
        }
        try {
            $this->workflow->linkCompany($projectKey, $caseId, (int) $data['company_id'], $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['company_id' => $exception->getMessage()]);
        }

        return back()->with('success', '询盘已关联客户公司。');
    }
}