<?php

namespace App\Modules\Sales\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use Illuminate\Contracts\Auth\Authenticatable;

final class SalesOwnerScope
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    // Only the expressly defined salesperson preset is "my records".
    // Management roles keep their existing project-wide permission contract.
    public function restrictedOwnerId(?Authenticatable $actor): ?int
    {
        if (! $actor instanceof User) {
            return null;
        }

        return $actor->roles()->where('key', RolePreset::SalesRepresentative->roleKey())->exists()
            ? (int) $actor->getAuthIdentifier()
            : null;
    }

    public function assertOpportunity(string $projectKey, int $opportunityId, ?Authenticatable $actor): void
    {
        $ownerId = $this->restrictedOwnerId($actor);
        if ($ownerId === null) {
            return;
        }

        $project = $this->projects->findByKey($projectKey);
        abort_unless($project && $project->status === 'active', 404);
        abort_unless(SalesOpportunity::query()
            ->where('business_project_id', $project->id)
            ->where('owner_id', $ownerId)
            ->whereKey($opportunityId)->exists(), 404);
    }

    /** @param array<string,mixed> $attributes */
    public function enforceCreation(string $projectKey, array &$attributes, ?Authenticatable $actor): void
    {
        $ownerId = $this->restrictedOwnerId($actor);
        if ($ownerId === null) {
            return;
        }
        abort_if(isset($attributes['owner_id']) && (int) $attributes['owner_id'] !== $ownerId, 403);

        $project = $this->projects->findByKey($projectKey);
        abort_unless($project && $project->status === 'active', 404);
        abort_unless(BusinessProjectCompany::query()
            ->where('business_project_id', $project->id)
            ->where('company_id', (int) $attributes['company_id'])
            ->where('owner_id', $ownerId)->exists(), 403);

        if (! empty($attributes['case_id'])) {
            abort_unless(InquiryCase::query()
                ->where('business_project_id', $project->id)
                ->where('owner_id', $ownerId)
                ->whereKey((int) $attributes['case_id'])->exists(), 404);
        }
        $attributes['owner_id'] = $ownerId;
    }
}
