<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Customers\Application\CustomerReceivedTruthService;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Inquiries\Application\InquiryCaseService;
use App\Modules\Identity\Application\SalespersonOwnerScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectCustomerController
{
    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly InquiryCaseService $inquiries,
        private readonly CustomerReceivedTruthService $receivedTruth,
        private readonly SalespersonOwnerScope $ownerScope,
    ) {}

    public function __invoke(Request $request, string $projectKey): Response
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new NotFoundHttpException('Business project not found.');
        }

        $ownerId = $this->ownerScope->ownerId($request->user());
        $relationships = BusinessProjectCompany::query()
            ->with(['company.identityReviewer', 'company.contacts', 'owner:id,name,email', 'location'])
            ->where('business_project_id', $project->id)
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->orderByDesc('last_inquiry_at')
            ->get();

        $receivedByCompany = $this->receivedTruth->totalsForProjectCompanies(
            $project->id,
            $relationships->pluck('company_id')->all(),
        );

        $unresolved = DB::table('cases')
            ->where('business_project_id', $project->id)
            ->whereNull('company_id')
            ->where('case_status', 'open')
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->count();

        $inquiryPayload = $this->inquiries->indexPayload($projectKey, ['status' => 'open'], $request->user());
        $pendingInquiries = collect($inquiryPayload['cases'])
            ->filter(fn (array $case): bool => $case['company'] === null)
            ->values()
            ->all();

        return Inertia::render('Customers/Project/Index', [
            'project' => [
                'key' => $project->project_key,
                'name' => $project->name,
                'icon_url' => config("yby_erp.project_branding.{$project->project_key}.icon_url"),
                'emoji' => (string) config("yby_erp.project_branding.{$project->project_key}.fallback_emoji", '◆'),
            ],
            'metrics' => [
                'companies' => $relationships->count(),
                'customers' => $relationships->where('relationship_status', 'customer')->count(),
                'prospects' => $relationships->where('relationship_status', 'prospect')->count(),
                'verified' => $relationships->filter(fn ($row) => $row->company?->identity_status === 'verified')->count(),
                'unresolved_inquiries' => $unresolved,
            ],
            'relationships' => $relationships->map(fn (BusinessProjectCompany $row): array => [
                'id' => $row->id,
                'company' => [
                    'id' => $row->company->id,
                    'name' => $row->company->name,
                    'country_code' => $row->company->country_code,
                    'identity_status' => $row->company->identity_status,
                    'scale_band' => $row->company->scale_band,
                    'primary_contact' => $row->company->contacts->first()?->name,
                ],
                'owner' => $row->owner ? ['id' => $row->owner->id, 'name' => $row->owner->name] : null,
                'location' => $row->location ? ['id' => $row->location->id, 'name' => $row->location->name] : null,
                'relationship_status' => $row->relationship_status,
                'market_route' => $row->market_route,
                'inquiry_count' => $row->inquiry_count,
                'last_source_channel' => $row->last_source_channel,
                'last_inquiry_at' => $row->last_inquiry_at?->toIso8601String(),
                'last_follow_up_at' => $row->last_follow_up_at?->toIso8601String(),
                'received_totals' => $receivedByCompany[$row->company_id] ?? [],
            ])->values()->all(),
            'pendingInquiries' => $pendingInquiries,
            'owners' => $inquiryPayload['owners'],
            'companies' => $inquiryPayload['companies'],
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
        ]);
    }
}
