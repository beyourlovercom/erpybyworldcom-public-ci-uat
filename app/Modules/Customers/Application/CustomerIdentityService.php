<?php

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Customers\Infrastructure\Models\CompanyIdentityEvidence;
use App\Modules\Customers\Infrastructure\Models\Customer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerIdentityService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function refreshProjectRelationship(int $projectId, int $companyId, ?int $ownerId = null): BusinessProjectCompany
    {
        $company = Company::query()->findOrFail($companyId);
        $facts = DB::table('cases')->where('business_project_id', $projectId)->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as inquiry_count')->selectRaw('MIN(first_inbound_at) as first_inquiry_at')
            ->selectRaw('MAX(last_inbound_at) as last_inquiry_at')->first();
        $firstSource = DB::table('cases')->where('business_project_id', $projectId)->where('company_id', $companyId)->orderBy('first_inbound_at')->value('source_channel');
        $lastSource = DB::table('cases')->where('business_project_id', $projectId)->where('company_id', $companyId)->orderByDesc('last_inbound_at')->value('source_channel');

        return BusinessProjectCompany::query()->updateOrCreate(
            ['business_project_id' => $projectId, 'company_id' => $company->id],
            [
                'owner_id' => $ownerId ?? $company->owner_id,
                'relationship_status' => $company->status === 'customer' ? 'customer' : 'prospect',
                'first_source_channel' => $firstSource,
                'last_source_channel' => $lastSource,
                'inquiry_count' => (int) ($facts->inquiry_count ?? 0),
                'first_inquiry_at' => $facts->first_inquiry_at ?? null,
                'last_inquiry_at' => $facts->last_inquiry_at ?? null,
            ]
        );
    }

    public function linkContact(int $companyId, int $customerId, array $attributes = [], ?Authenticatable $actor = null): Company
    {
        $company = Company::query()->findOrFail($companyId);
        $contact = Customer::query()->findOrFail($customerId);
        if (($attributes['is_primary'] ?? false) === true) {
            DB::table('company_contacts')->where('company_id', $companyId)->update(['is_primary' => false]);
        }
        $company->contacts()->syncWithoutDetaching([
            $customerId => [
                'job_title' => $attributes['job_title'] ?? null,
                'department' => $attributes['department'] ?? null,
                'is_primary' => (bool) ($attributes['is_primary'] ?? false),
                'status' => $attributes['status'] ?? 'active',
                'started_at' => $attributes['started_at'] ?? now(),
                'ended_at' => $attributes['ended_at'] ?? null,
            ],
        ]);
        $this->record($actor, 'company.contact.linked', $company, ['contact_id' => $contact->id], 'contact_linked');
        return $company->fresh(['contacts']);
    }

    public function recordEvidence(int $companyId, array $attributes, ?Authenticatable $actor = null): CompanyIdentityEvidence
    {
        $company = Company::query()->findOrFail($companyId);
        $reviewState = (string) ($attributes['review_state'] ?? 'observed');
        if (! in_array($reviewState, ['observed', 'verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid identity evidence review state.');
        }
        $evidence = CompanyIdentityEvidence::query()->updateOrCreate(
            ['company_id' => $company->id, 'evidence_type' => (string) $attributes['evidence_type'], 'source_type' => (string) $attributes['source_type'], 'evidence_key' => (string) $attributes['evidence_key']],
            [
                'business_project_id' => $attributes['business_project_id'] ?? null,
                'evidence_value' => $attributes['evidence_value'] ?? null,
                'review_state' => $reviewState,
                'confidence' => $attributes['confidence'] ?? null,
                'source_url' => $attributes['source_url'] ?? null,
                'observed_at' => $attributes['observed_at'] ?? now(),
                'verified_at' => $reviewState === 'verified' ? now() : null,
                'verified_by' => $reviewState === 'verified' ? $actor?->getAuthIdentifier() : null,
            ]
        );
        $this->record($actor, 'company.identity.evidence_recorded', $company, ['evidence_id' => $evidence->id, 'review_state' => $reviewState], 'identity_evidence');
        return $evidence->fresh();
    }

    public function reviewCompany(int $companyId, string $identityStatus, ?string $scaleBand, ?Authenticatable $actor): Company
    {
        if (! in_array($identityStatus, ['unverified', 'reviewing', 'verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid company identity status.');
        }
        if ($scaleBand !== null && ! in_array($scaleBand, ['micro', 'small', 'medium', 'large', 'enterprise'], true)) {
            throw new InvalidArgumentException('Invalid company scale band.');
        }
        $company = Company::query()->findOrFail($companyId);
        $before = ['identity_status' => $company->identity_status, 'scale_band' => $company->scale_band];
        $company->forceFill([
            'identity_status' => $identityStatus,
            'scale_band' => $scaleBand,
            'identity_reviewed_at' => now(),
            'identity_reviewed_by' => $actor?->getAuthIdentifier(),
        ])->save();
        $this->record($actor, 'company.identity.reviewed', $company, ['before' => $before, 'after' => ['identity_status' => $identityStatus, 'scale_band' => $scaleBand]], 'identity_review');
        return $company->fresh(['identityReviewer', 'identityEvidence']);
    }

    private function record(?Authenticatable $actor, string $action, Company $company, array $after, string $reason): void
    {
        $auditActor = $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem);
        $this->audit->record($auditActor, $action, Company::class, $company->id, after: $after, reason: $reason);
    }
}