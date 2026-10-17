<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Customers\Application\CompanyService;
use App\Modules\Customers\Application\CustomerIdentityService;
use App\Modules\Customers\Application\CustomerReceivedTruthService;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use App\Modules\Customers\Domain\WebsiteDomainNormalizer;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Application\SalespersonOwnerScope;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly CustomerIdentityService $identity,
        private readonly CustomerReceivedTruthService $receivedTruth,
        private readonly SalespersonOwnerScope $ownerScope,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'country_code', 'owner_id', 'archived']);
        $restricted = $this->ownerScope->ownerId($request->user());
        $listFilters = $filters;
        if ($restricted !== null) {
            $listFilters['salesperson_owner_id'] = $restricted;
        }
        $companies = $this->companies->list($listFilters);

        return Inertia::render('Customers/Companies/Index', [
            'companies' => $companies->map(fn (Company $company): array => $this->companyData($company))->values()->all(),
            'filters' => $filters,
            'owners' => User::query()->where('is_active', true)
                ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
                ->orderBy('name')->get(['id', 'name']),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
        ]);
    }

    public function create(Request $request): Response
    {
        $restricted = $this->ownerScope->ownerId($request->user());
        return Inertia::render('Customers/Companies/Create', [
            'owners' => User::query()->where('is_active', true)
                ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $restricted = $this->ownerScope->ownerId($request->user());
        if ($restricted !== null) {
            abort_if(isset($data['owner_id']) && (int) $data['owner_id'] !== $restricted, 403);
            $data['owner_id'] = $restricted;
        }
        $company = $this->companies->create($data, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已创建');
    }

    public function show(Request $request, Company $company): Response
    {
        $this->assertVisible($request, $company);
        $restricted = $this->ownerScope->ownerId($request->user());
        $company->load(['owner', 'identityReviewer', 'contacts', 'projectRelationships.project', 'projectRelationships.owner', 'projectRelationships.location', 'identityEvidence.project', 'identityEvidence.verifier']);

        if ($restricted !== null) {
            $company->load(['projectRelationships' => fn ($query) => $query->where('owner_id', $restricted)
                ->with(['project', 'owner', 'location'])]);
            $projectIds = $company->projectRelationships->pluck('business_project_id')->all();
            $company->load(['identityEvidence' => fn ($query) => $query
                ->whereIn('business_project_id', $projectIds)->with(['project', 'verifier'])]);
        }
        $receivedByProject = $this->receivedTruth->totalsForCompanyProjects(
            $company->id,
            $company->projectRelationships->pluck('business_project_id')->all(),
        );

        return Inertia::render('Customers/Companies/Show', [
            'company' => $this->companyData($company),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
            'contacts' => $company->contacts->map(fn ($contact): array => [
                'id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->phone,
                'job_title' => $contact->pivot->job_title, 'department' => $contact->pivot->department,
                'is_primary' => (bool) $contact->pivot->is_primary, 'status' => $contact->pivot->status,
            ])->values()->all(),
            'projectRelationships' => $company->projectRelationships->map(fn ($row): array => [
                'project' => ['id' => $row->project->id, 'key' => $row->project->project_key, 'name' => $row->project->name],
                'owner' => $row->owner ? ['id' => $row->owner->id, 'name' => $row->owner->name] : null,
                'location' => $row->location ? ['id' => $row->location->id, 'name' => $row->location->name] : null,
                'relationship_status' => $row->relationship_status, 'market_route' => $row->market_route,
                'payment_terms' => $row->payment_terms, 'credit_limit_micros' => $row->credit_limit_micros,
                'credit_currency' => $row->credit_currency, 'inquiry_count' => $row->inquiry_count,
                'last_source_channel' => $row->last_source_channel, 'last_inquiry_at' => $row->last_inquiry_at?->toIso8601String(),
                'received_totals' => $receivedByProject[$row->business_project_id] ?? [],
            ])->values()->all(),
            'identityEvidence' => $company->identityEvidence->map(fn ($evidence): array => [
                'id' => $evidence->id, 'evidence_type' => $evidence->evidence_type, 'source_type' => $evidence->source_type,
                'evidence_key' => $evidence->evidence_key, 'review_state' => $evidence->review_state,
                'confidence' => $evidence->confidence, 'source_url' => $evidence->source_url,
                'project' => $evidence->project ? ['key' => $evidence->project->project_key, 'name' => $evidence->project->name] : null,
                'observed_at' => $evidence->observed_at?->toIso8601String(), 'verified_at' => $evidence->verified_at?->toIso8601String(),
            ])->values()->all(),
            'audit' => AuditRecord::query()
                ->where('entity_type', Company::class)
                ->where('entity_id', (string) $company->getKey())
                ->when($restricted !== null, fn ($query) => $query->where('actor_id', $restricted))
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditRecord $record): array => [
                    'action' => $record->action,
                    'actor_id' => $record->actor_id,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function edit(Request $request, Company $company): Response
    {
        $this->assertEditable($request, $company);
        $restricted = $this->ownerScope->ownerId($request->user());
        return Inertia::render('Customers/Companies/Edit', [
            'company' => $this->companyData($company),
            'owners' => User::query()->where('is_active', true)
                ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->assertEditable($request, $company);
        $data = $request->validate($this->updateRules());
        $restricted = $this->ownerScope->ownerId($request->user());
        if ($restricted !== null) {
            abort_if(isset($data['owner_id']) && (int) $data['owner_id'] !== $restricted, 403);
            $data['owner_id'] = $restricted;
        }
        $this->companies->update($company, $data, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已更新');
    }

    public function archive(Request $request, Company $company): RedirectResponse
    {
        $this->assertEditable($request, $company);
        $this->companies->archive($company, $request->user());

        return redirect()->route('customers.companies')->with('success', '公司已归档');
    }

    public function restore(Request $request, Company $company): RedirectResponse
    {
        $this->assertEditable($request, $company);
        $this->companies->restore($company, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已取消归档');
    }

    public function reviewIdentity(Request $request, Company $company): RedirectResponse
    {
        $this->assertVisible($request, $company);
        abort_if($this->ownerScope->ownerId($request->user()) !== null, 403);
        $data = $request->validate([
            'identity_status' => ['required', 'in:unverified,reviewing,verified,rejected'],
            'scale_band' => ['nullable', 'in:micro,small,medium,large,enterprise'],
        ]);
        $this->identity->reviewCompany($company->id, $data['identity_status'], $data['scale_band'] ?? null, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司身份状态已更新');
    }

    private function assertVisible(Request $request, Company $company): void
    {
        $restricted = $this->ownerScope->ownerId($request->user());
        if ($restricted === null) {
            return;
        }
        abort_unless((int) $company->owner_id === $restricted || $company->projectRelationships()
            ->where('owner_id', $restricted)->exists(), 404);
    }

    private function assertEditable(Request $request, Company $company): void
    {
        $this->assertVisible($request, $company);
        $restricted = $this->ownerScope->ownerId($request->user());
        if ($restricted === null) {
            return;
        }
        abort_if($company->owner_id !== null && (int) $company->owner_id !== $restricted, 403);
        abort_if($company->projectRelationships()->where(fn ($query) => $query
            ->whereNull('owner_id')->orWhere('owner_id', '!=', $restricted))->exists(), 403);
    }

    private function companyData(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'website_url' => $company->website_url,
            'website_domain_normalized' => $company->website_domain_normalized,
            'country_code' => $company->country_code,
            'registration_number' => $company->registration_number,
            'industry' => $company->industry,
            'owner' => $company->owner ? ['id' => $company->owner->id, 'name' => $company->owner->name] : null,
            'status' => $company->status,
            'identity_status' => $company->identity_status ?? 'unverified',
            'scale_band' => $company->scale_band,
            'identity_reviewer' => $company->identityReviewer ? ['id' => $company->identityReviewer->id, 'name' => $company->identityReviewer->name] : null,
            'identity_reviewed_at' => $company->identity_reviewed_at?->toIso8601String(),
            'archived_at' => $company->archived_at?->toIso8601String(),
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:500', $this->websiteRule()],
            'country_code' => ['nullable', 'string', 'max:8', $this->countryRule()],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'industry' => ['nullable', 'string', 'max:191'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:500', $this->websiteRule()],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8', $this->countryRule()],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:191'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:191'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    private function websiteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            try {
                WebsiteDomainNormalizer::normalize($value);
            } catch (InvalidCompanyAttributeException) {
                $fail('网址无效，请提供包含协议（如 https://）的有效网址。');
            }
        };
    }

    private function countryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            try {
                CountryCodeNormalizer::normalize($value);
            } catch (InvalidCompanyAttributeException) {
                $fail('国家代码需为两位字母。');
            }
        };
    }
}
