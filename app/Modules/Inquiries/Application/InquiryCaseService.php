<?php

namespace App\Modules\Inquiries\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Customers\Application\CompanyService;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Identity\Application\SalespersonOwnerScope;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use InvalidArgumentException;

final class InquiryCaseService
{
    public function __construct(
        private readonly InquiryCaseRepository $cases,
        private readonly BusinessProjectService $projects,
        private readonly CompanyService $companies,
        private readonly SalespersonOwnerScope $ownerScope,
    ) {}

    public function indexPayload(string $projectKey, array $filters = [], ?Authenticatable $actor = null): array
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        $ownerId = $this->ownerScope->ownerId($actor);
        if ($ownerId !== null) {
            $filters['owner_id'] = $ownerId;
        }
        $visibleCompanies = $ownerId === null ? $this->companies->list()
            : BusinessProjectCompany::query()->with('company:id,name')
                ->where('business_project_id', $project->id)
                ->where('owner_id', $ownerId)->get()->pluck('company')->filter();

        return [
            'project' => ['key' => $project->project_key, 'name' => $project->name],
            'filters' => [
                'status' => trim((string) ($filters['status'] ?? '')),
                'stage' => trim((string) ($filters['stage'] ?? '')),
                'source' => trim((string) ($filters['source'] ?? '')),
            ],
            'summary' => $this->cases->countsForProject((int) $project->id, $ownerId),
            'owners' => $this->cases->listAssignableOwners()
                ->filter(fn ($owner) => $ownerId === null || (int) $owner->id === $ownerId)
                ->map(fn ($owner) => [
                'id' => (int) $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ])->values()->all(),
            'companies' => $visibleCompanies->map(fn ($company) => [
                'id' => (int) $company->id,
                'name' => $company->name,
            ])->values()->all(),
            'cases' => $this->cases->listForProject((int) $project->id, $filters)->map(fn ($case) => [
                'id' => (int) $case->id,
                'case_number' => $case->case_number,
                'title' => $case->title,
                'contact_name' => $case->contact_name,
                'contact_email' => $case->contact_email,
                'contact_phone' => $case->contact_phone,
                'company_name_snapshot' => $case->company_name_snapshot,
                'country_snapshot' => $case->country_snapshot,
                'buyer_type' => $case->buyer_type,
                'product_interest' => $case->product_interest,
                'quantity' => $case->quantity,
                'source_channel' => $case->source_channel,
                'source_site_key' => $case->source_site_key,
                'case_status' => $case->case_status,
                'case_stage' => $case->case_stage,
                'handling_state' => $case->handling_state,
                'identity_resolution_state' => $case->identity_resolution_state,
                'first_inbound_at' => $case->first_inbound_at?->toIso8601String(),
                'last_inbound_at' => $case->last_inbound_at?->toIso8601String(),
                'company' => $case->company ? ['id' => $case->company->id, 'name' => $case->company->name] : null,
                'owner' => $case->owner ? ['id' => $case->owner->id, 'name' => $case->owner->name] : null,
            ])->values()->all(),
        ];
    }
}