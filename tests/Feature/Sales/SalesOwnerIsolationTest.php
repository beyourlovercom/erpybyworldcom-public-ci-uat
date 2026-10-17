<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Application\SalesOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesOwnerIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_salesperson_cannot_list_open_or_filter_other_owners_sales_records(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $alice = $this->userWithPreset(RolePreset::SalesRepresentative);
        $bob = $this->userWithPreset(RolePreset::SalesRepresentative);
        $company = Company::create(['name' => 'Synthetic Sales Isolation Buyer', 'status' => 'prospect', 'identity_status' => 'verified']);
        $sales = app(SalesOpportunityService::class);
        $own = $sales->create($project->project_key, ['company_id' => $company->id, 'owner_id' => $alice->id, 'title' => 'Alice only'], $alice);
        $foreign = $sales->create($project->project_key, ['company_id' => $company->id, 'owner_id' => $bob->id, 'title' => 'Bob private'], $bob);
        app(PaymentReceiptService::class)->record($project->project_key, $foreign->id, [
            'amount_micros' => 100000000, 'currency' => 'USD', 'payment_method' => 'bank_transfer',
            'received_at' => '2026-10-09 12:00:00', 'provider_reference' => 'ISOLATED-FAKE-BOB',
        ], $bob);

        $this->actingAs($alice)->get('/projects/yby-irrigation/sales')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('opportunities', 1)->where('opportunities.0.id', $own->id)->where('metrics.open', 1));
        $this->actingAs($alice)->get('/projects/yby-irrigation/sales?owner_id='.$bob->id)
            ->assertForbidden();
        $this->actingAs($alice)->get('/projects/yby-irrigation/sales/opportunities/'.$own->id)->assertOk();
        $this->actingAs($alice)->get('/projects/yby-irrigation/sales/opportunities/'.$foreign->id)->assertNotFound();
        $this->actingAs($bob)->get('/projects/yby-irrigation/sales')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('opportunities', 1)->where('opportunities.0.id', $foreign->id));
        $this->actingAs($alice)->post('/projects/yby-irrigation/sales/opportunities/'.$foreign->id.'/payment-receipts', [
            'amount_micros' => 50000000, 'currency' => 'USD', 'payment_method' => 'bank_transfer',
            'received_at' => '2026-10-09 12:30:00',
        ])->assertNotFound();
        $this->actingAs($alice)->post('/projects/yby-irrigation/sales/opportunities', [
            'company_id' => $company->id, 'owner_id' => $bob->id, 'title' => 'Forged assignment',
        ])->assertForbidden();
        $this->actingAs($alice)->get('/projects/yby-irrigation/sales/receipts?date_from=2026-10-09&date_to=2026-10-09')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('receipts', 0)->has('confirmed_totals', 0));
        $this->actingAs($alice)->post('/projects/yby-irrigation/sales/opportunities/'.$foreign->id.'/won')->assertNotFound();
    }

    private function userWithPreset(RolePreset $preset): User
    {
        $role = Role::firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        $ids = collect($preset->permissions())->map(fn (string $key) =>
            Permission::firstOrCreate(['key' => $key], ['label' => $key])->id
        )->all();
        $role->permissions()->sync($ids);
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);
        return $user;
    }
}
