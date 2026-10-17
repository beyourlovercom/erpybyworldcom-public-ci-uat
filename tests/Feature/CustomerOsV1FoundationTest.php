<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Application\CustomerIdentityService;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Customers\Infrastructure\Models\CompanyIdentityEvidence;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerOsV1FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_company_relationship_is_projection_of_case_truth(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect']);
        $this->case($project->id, $company->id, 'website', '2026-09-15 08:00:00', 'CASE-1');
        $this->case($project->id, $company->id, 'alibaba', '2026-09-16 09:00:00', 'CASE-2');

        $relationship = $this->app->make(CustomerIdentityService::class)
            ->refreshProjectRelationship($project->id, $company->id);

        $this->assertSame(2, $relationship->inquiry_count);
        $this->assertSame('website', $relationship->first_source_channel);
        $this->assertSame('alibaba', $relationship->last_source_channel);
        $this->assertSame(1, BusinessProjectCompany::count());
    }

    public function test_contact_linking_keeps_one_primary_contact_per_company(): void
    {
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect']);
        $first = Customer::create(['name' => 'Buyer One', 'email' => 'one@example.com']);
        $second = Customer::create(['name' => 'Buyer Two', 'email' => 'two@example.com']);
        $service = $this->app->make(CustomerIdentityService::class);

        $service->linkContact($company->id, $first->id, ['is_primary' => true, 'job_title' => 'Procurement']);
        $service->linkContact($company->id, $second->id, ['is_primary' => true, 'job_title' => 'Director']);

        $this->assertSame(2, $company->fresh()->contacts()->count());
        $this->assertSame(1, (int) $company->fresh()->contacts()->wherePivot('is_primary', true)->count());
        $this->assertSame($second->id, $company->fresh()->contacts()->wherePivot('is_primary', true)->firstOrFail()->id);
    }

    public function test_identity_evidence_and_review_status_are_explicit_truth(): void
    {
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect']);
        $reviewer = User::factory()->create(['is_active' => true]);
        $service = $this->app->make(CustomerIdentityService::class);

        $evidence = $service->recordEvidence($company->id, [
            'evidence_type' => 'website_domain', 'source_type' => 'manual_review',
            'evidence_key' => 'acme.example', 'evidence_value' => ['domain' => 'acme.example'],
            'review_state' => 'verified', 'confidence' => 0.95,
        ], $reviewer);
        $reviewed = $service->reviewCompany($company->id, 'verified', 'medium', $reviewer);

        $this->assertSame('verified', $evidence->review_state);
        $this->assertSame(1, CompanyIdentityEvidence::count());
        $this->assertSame('verified', $reviewed->identity_status);
        $this->assertSame('medium', $reviewed->scale_band);
        $this->assertSame($reviewer->id, $reviewed->identity_reviewed_by);
    }

    private function case(int $projectId, int $companyId, string $source, string $at, string $externalId): InquiryCase
    {
        return InquiryCase::create([
            'case_number' => 'YBY-'.str_replace('-', '', $externalId),
            'business_project_id' => $projectId,
            'company_id' => $companyId,
            'title' => 'Wholesale inquiry',
            'source_channel' => $source,
            'source_connection_key' => 'test',
            'source_external_id' => $externalId,
            'source_payload_hash' => hash('sha256', $externalId),
            'case_status' => 'open',
            'case_stage' => 'new',
            'handling_state' => 'waiting',
            'identity_resolution_state' => 'resolved',
            'first_inbound_at' => $at,
            'last_inbound_at' => $at,
        ]);
    }
}
