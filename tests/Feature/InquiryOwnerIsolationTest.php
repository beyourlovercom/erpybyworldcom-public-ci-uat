<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InquiryOwnerIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_salesperson_cannot_read_or_reassign_foreign_inquiries_or_project_companies(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $alice = $this->salesperson();
        $bob = $this->salesperson();
        $aliceCompany = Company::create(['name' => 'Alice Private Company', 'status' => 'prospect']);
        $bobCompany = Company::create(['name' => 'Bob Private Company', 'status' => 'prospect']);
        foreach ([[$alice,$aliceCompany],[$bob,$bobCompany]] as [$actor,$company]) {
            BusinessProjectCompany::create([
                'business_project_id' => $project->id, 'company_id' => $company->id,
                'owner_id' => $actor->id, 'relationship_status' => 'prospect',
            ]);
        }
        $own = $this->caseFor($project, $alice, 'alice');
        $foreign = $this->caseFor($project, $bob, 'bob');

        $this->actingAs($alice)->get('/projects/yby-irrigation/inquiries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('cases', 1)->where('cases.0.id', $own->id)->where('summary.total', 1));
        $this->actingAs($alice)->get('/projects/yby-irrigation/customers')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('relationships', 1)->where('relationships.0.company.id', $aliceCompany->id));
        $this->actingAs($alice)->get('/customers')->assertForbidden();
        $this->actingAs($alice)->get('/customers/library')->assertForbidden();
        $this->actingAs($alice)->get('/customers/groups')->assertForbidden();
        $this->actingAs($alice)->get('/customers/companies')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 1)->where('companies.0.id', $aliceCompany->id)
                ->has('owners', 1)->where('owners.0.id', $alice->id));
        $this->actingAs($alice)->get('/customers/companies/'.$bobCompany->id)->assertNotFound();
        $this->actingAs($alice)->post('/customers/companies/'.$bobCompany->id.'/archive')->assertNotFound();
        $this->actingAs($alice)->get('/customers/companies/'.$aliceCompany->id)->assertOk();
        $this->actingAs($alice)->post('/projects/yby-irrigation/inquiries/'.$foreign->id.'/assignment', [
            'owner_id' => $alice->id,
        ])->assertNotFound();
        $this->actingAs($alice)->post('/projects/yby-irrigation/inquiries/'.$foreign->id.'/identity/company', [
            'company_id' => $aliceCompany->id,
        ])->assertNotFound();
        $admin = $this->roleUser(RolePreset::Administrator);
        $this->actingAs($admin)->get('/projects/yby-irrigation/inquiries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('cases', 2));
        $this->actingAs($admin)->get('/projects/yby-irrigation/customers')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('relationships', 2));
        $this->actingAs($admin)->get('/customers/companies')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('companies', 2));
    }

    private function caseFor(BusinessProject $project, User $owner, string $key): InquiryCase
    {
        return InquiryCase::create([
            'case_number' => 'U2-'.strtoupper($key), 'business_project_id' => $project->id,
            'title' => 'Synthetic '.strtoupper($key), 'source_channel' => 'manual',
            'source_connection_key' => 'local-u2', 'source_external_id' => $key,
            'source_payload_hash' => str_repeat($key === 'alice' ? 'a' : 'b', 64),
            'owner_id' => $owner->id, 'case_status' => 'open', 'case_stage' => 'new',
            'handling_state' => 'in_progress', 'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);
    }

    private function salesperson(): User
    {
        return $this->roleUser(RolePreset::SalesRepresentative);
    }

    private function roleUser(RolePreset $preset): User
    {
        $role = Role::firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        $role->permissions()->sync(collect($preset->permissions())
            ->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['label' => $key])->id)->all());
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);
        return $user;
    }
}
