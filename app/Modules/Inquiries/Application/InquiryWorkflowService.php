<?php

namespace App\Modules\Inquiries\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Customers\Application\CompanyService;
use App\Modules\Customers\Application\CustomerIdentityService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InquiryWorkflowService
{
    public function __construct(
        private readonly InquiryCaseRepository $cases,
        private readonly BusinessProjectService $projects,
        private readonly CompanyService $companies,
        private readonly CustomerIdentityService $customerIdentity,
        private readonly AuditRecorder $audit,
    ) {}

    public function assign(string $projectKey, int $caseId, ?int $ownerId, ?Authenticatable $actor): object
    {
        $project = $this->project($projectKey);
        $owner = null;
        if ($ownerId !== null) {
            $owner = User::query()->where('is_active', true)->find($ownerId);
            if (! $owner) {
                throw new InvalidArgumentException('Assignable owner not found.');
            }
        }

        return DB::transaction(function () use ($project, $caseId, $owner, $actor): object {
            $case = $this->cases->findForProject((int) $project->id, $caseId);
            $before = ['owner_id' => $case->owner_id, 'handling_state' => $case->handling_state];
            $case->owner_id = $owner?->id;
            $case->handling_state = $owner ? 'in_progress' : 'waiting';
            if ($owner && $case->first_action_at === null) {
                $case->first_action_at = now();
            }
            $case->last_action_at = now();
            $case->save();
            $after = ['owner_id' => $case->owner_id, 'handling_state' => $case->handling_state];
            $this->record($actor, 'inquiry.assignment.updated', $case, $before, $after, 'owner_assignment');

            if ($case->company_id !== null) {
                $this->customerIdentity->refreshProjectRelationship(
                    (int) $project->id,
                    (int) $case->company_id,
                    $owner?->id,
                );
            }

            return $case->fresh(['owner:id,name,email', 'company:id,name']);
        });
    }

    public function linkCompany(string $projectKey, int $caseId, int $companyId, ?Authenticatable $actor): object
    {
        $project = $this->project($projectKey);
        $company = $this->companies->find($companyId);

        return DB::transaction(function () use ($project, $caseId, $company, $actor): object {
            $case = $this->cases->findForProject((int) $project->id, $caseId);
            $before = ['company_id' => $case->company_id, 'identity_resolution_state' => $case->identity_resolution_state];
            $case->company_id = (int) $company->id;
            $case->identity_resolution_state = 'resolved';
            $case->last_action_at = now();
            if ($case->first_action_at === null) {
                $case->first_action_at = now();
            }
            $case->save();

            $this->customerIdentity->refreshProjectRelationship(
                (int) $project->id,
                (int) $company->id,
                $case->owner_id ? (int) $case->owner_id : null,
            );

            $after = ['company_id' => $case->company_id, 'identity_resolution_state' => $case->identity_resolution_state];
            $this->record($actor, 'inquiry.identity.linked', $case, $before, $after, 'company_linked');

            return $case->fresh(['owner:id,name,email', 'company:id,name']);
        });
    }

    private function project(string $projectKey): object
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }
        return $project;
    }

    private function record(?Authenticatable $actor, string $action, object $case, array $before, array $after, string $reason): void
    {
        $auditActor = $actor
            ? AuditActor::user($actor, AuditSource::InternalSystem)
            : AuditActor::system(AuditSource::InternalSystem);
        $this->audit->record($auditActor, $action, get_class($case), $case->id, $before, $after, $reason);
    }
}
