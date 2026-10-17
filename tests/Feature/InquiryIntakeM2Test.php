<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Application\Exceptions\InquiryIntakeConflict;
use App\Modules\Inquiries\Application\InquiryIntakeService;
use App\Modules\Inquiries\Application\InquiryWorkflowService;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InquiryIntakeM2Test extends TestCase
{
    use RefreshDatabase;

    public function test_website_intake_resolves_project_from_registered_site_and_is_idempotent(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        BusinessProjectSite::create([
            'business_project_id' => $project->id, 'site_key' => 'ybyirrigation-com',
            'domain_normalized' => 'ybyirrigation.com', 'base_url' => 'https://ybyirrigation.com',
            'status' => 'active', 'is_primary' => true,
        ]);
        $input = $this->websiteInput();
        $service = $this->app->make(InquiryIntakeService::class);

        $first = $service->ingest($input);
        $second = $service->ingest($input);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, InquiryCase::count());
        $case = InquiryCase::firstOrFail();
        $this->assertSame($project->id, $case->business_project_id);
        $this->assertSame('ybyirrigation-com', $case->source_connection_key);
        $this->assertSame('website', $case->source_channel);
        $this->assertSame('buyer@example.com', $case->contact_email);
        $this->assertSame('google', $case->attribution['utm_source']);
        $this->assertSame('tanzania', $case->attribution['utm_campaign']);
        $this->assertSame('unresolved', $case->identity_resolution_state);
    }

    public function test_duplicate_source_record_with_changed_payload_is_blocked(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        BusinessProjectSite::create([
            'business_project_id' => $project->id, 'site_key' => 'ybyirrigation-com',
            'domain_normalized' => 'ybyirrigation.com', 'base_url' => 'https://ybyirrigation.com',
            'status' => 'active', 'is_primary' => true,
        ]);
        $service = $this->app->make(InquiryIntakeService::class);
        $service->ingest($this->websiteInput());

        $this->expectException(InquiryIntakeConflict::class);
        $service->ingest([...$this->websiteInput(), 'contact_email' => 'changed@example.com']);
    }

    public function test_assignment_and_company_identity_link_update_case_truth(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $owner = User::factory()->create(['name' => 'YBY Sales', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect']);
        $case = InquiryCase::create([
            'case_number' => 'YBY-IRR-260916-ABC123', 'business_project_id' => $project->id,
            'title' => 'Wholesale inquiry', 'source_channel' => 'manual', 'source_connection_key' => 'uat',
            'source_external_id' => 'uat-1', 'source_payload_hash' => str_repeat('a', 64),
            'case_status' => 'open', 'case_stage' => 'new', 'handling_state' => 'waiting',
            'identity_resolution_state' => 'unresolved', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);
        $workflow = $this->app->make(InquiryWorkflowService::class);

        $workflow->assign('yby-irrigation', $case->id, $owner->id, $owner);
        $workflow->linkCompany('yby-irrigation', $case->id, $company->id, $owner);

        $case->refresh();
        $this->assertSame($owner->id, $case->owner_id);
        $this->assertSame('in_progress', $case->handling_state);
        $this->assertSame($company->id, $case->company_id);
        $this->assertSame('resolved', $case->identity_resolution_state);
        $this->assertNotNull($case->first_action_at);
    }

    private function websiteInput(): array
    {
        return [
            'source_channel' => 'website', 'site' => 'https://ybyirrigation.com/',
            'source_external_id' => 'YBY-IRR-TEST-001', 'contact_name' => 'Buyer',
            'contact_email' => 'Buyer@Example.com', 'company_name' => 'Acme Irrigation',
            'country' => 'TZ', 'product_interest' => 'Drip irrigation', 'quantity' => '1000',
            'first_inbound_at' => '2026-09-16T09:00:00+08:00', 'last_inbound_at' => '2026-09-16T09:00:00+08:00',
            'attribution' => ['utm_source' => 'google', 'utm_campaign' => 'tanzania'],
        ];
    }
}