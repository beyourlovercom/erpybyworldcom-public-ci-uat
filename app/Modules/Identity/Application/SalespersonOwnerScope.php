<?php

namespace App\Modules\Identity\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Contracts\Auth\Authenticatable;

final class SalespersonOwnerScope
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    public function ownerId(?Authenticatable $actor): ?int
    {
        if (! $actor instanceof User) {
            return null;
        }

        return $actor->roles()->where('key', RolePreset::SalesRepresentative->roleKey())->exists()
            ? (int) $actor->getAuthIdentifier()
            : null;
    }

    public function assertInquiry(string $projectKey, int $caseId, ?Authenticatable $actor): void
    {
        $ownerId = $this->ownerId($actor);
        if ($ownerId === null) {
            return;
        }

        $project = $this->projects->findByKey($projectKey);
        abort_unless($project && $project->status === 'active', 404);
        abort_unless(InquiryCase::query()
            ->where('business_project_id', $project->id)
            ->where('owner_id', $ownerId)->whereKey($caseId)->exists(), 404);
    }
}
