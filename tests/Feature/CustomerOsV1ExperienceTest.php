<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerOsV1ExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_customer_os_separates_relationships_from_unresolved_inquiries(): void
    {
        $user = $this->userWith(['customers.view']);
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect', 'identity_status' => 'verified']);
        BusinessProjectCompany::create([
            'business_project_id' => $project->id, 'company_id' => $company->id,
            'relationship_status' => 'prospect', 'inquiry_count' => 2,
            'last_source_channel' => 'website', 'last_inquiry_at' => '2026-09-16 10:00:00',
        ]);
        InquiryCase::create([
            'case_number' => 'YBY-IRR-UNRESOLVED', 'business_project_id' => $project->id,
            'title' => 'Unresolved website lead', 'source_channel' => 'website',
            'case_status' => 'open', 'case_stage' => 'new', 'handling_state' => 'waiting',
            'identity_resolution_state' => 'unresolved', 'first_inbound_at' => '2026-09-16 11:00:00',
            'last_inbound_at' => '2026-09-16 11:00:00',
        ]);

        $this->actingAs($user)->get('/projects/yby-irrigation/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Project/Index')
                ->where('metrics.companies', 1)
                ->where('metrics.verified', 1)
                ->where('metrics.unresolved_inquiries', 1)
                ->where('relationships.0.company.name', 'Acme Irrigation')
                ->where('relationships.0.inquiry_count', 2)
                ->where('pendingInquiries.0.case_number', 'YBY-IRR-UNRESOLVED')
                ->where('pendingInquiries.0.company', null));
    }

    public function test_project_customer_os_isolated_across_business_projects(): void
    {
        $user = $this->userWith(['customers.view'], 'project_reader');
        foreach ([
            'yby-irrigation' => 'YBY Irrigation',
            'yby-bottle' => 'YBY Bottle',
            'yby-world' => 'YBY World',
        ] as $key => $name) {
            BusinessProject::create(['project_key' => $key, 'name' => $name, 'status' => 'active']);
        }

        InquiryCase::create([
            'case_number' => 'BOTTLE-ONLY-001',
            'business_project_id' => BusinessProject::where('project_key', 'yby-bottle')->value('id'),
            'title' => 'Bottle inquiry', 'source_channel' => 'website',
            'case_status' => 'open', 'case_stage' => 'new', 'handling_state' => 'waiting',
            'identity_resolution_state' => 'unresolved', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);

        $this->actingAs($user)->get('/projects/yby-irrigation/customers')->assertInertia(fn (Assert $page) => $page->where('metrics.unresolved_inquiries', 0));
        $this->actingAs($user)->get('/projects/yby-bottle/customers')->assertInertia(fn (Assert $page) => $page->where('metrics.unresolved_inquiries', 1)->where('pendingInquiries.0.case_number', 'BOTTLE-ONLY-001'));
        $this->actingAs($user)->get('/projects/yby-world/customers')->assertInertia(fn (Assert $page) => $page->where('metrics.unresolved_inquiries', 0));
    }
    public function test_operator_can_review_company_identity_with_audit(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate'], 'customer_operator');
        $company = Company::create(['name' => 'Review Target', 'status' => 'prospect']);

        $this->actingAs($user)->post('/customers/companies/'.$company->id.'/identity-review', [
            'identity_status' => 'verified', 'scale_band' => 'medium',
        ])->assertRedirect('/customers/companies/'.$company->id);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'identity_status' => 'verified', 'scale_band' => 'medium']);
        $this->assertDatabaseHas('audit_records', ['action' => 'company.identity.reviewed', 'entity_id' => (string) $company->id]);
    }

    private function userWith(array $permissions, string $roleKey = 'customer_reader'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}