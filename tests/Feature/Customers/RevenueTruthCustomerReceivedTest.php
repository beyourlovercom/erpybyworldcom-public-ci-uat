<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Application\CustomerReceivedTruthService;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Application\SalesOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class RevenueTruthCustomerReceivedTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_received_truth_is_derived_from_confirmed_receipts_by_project_and_currency(): void
    {
        [$user, $irrigation, $bottle, $company] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $payments = $this->app->make(PaymentReceiptService::class);
        $received = $this->app->make(CustomerReceivedTruthService::class);

        $irrigationOpportunity = $sales->create($irrigation->project_key, [
            'company_id' => $company->id,
            'owner_id' => $user->id,
            'title' => 'Irrigation order',
            'currency' => 'USD',
        ], $user);
        $bottleOpportunity = $sales->create($bottle->project_key, [
            'company_id' => $company->id,
            'owner_id' => $user->id,
            'title' => 'Bottle order',
            'currency' => 'EUR',
        ], $user);

        $payments->record($irrigation->project_key, $irrigationOpportunity->id, [
            'amount_micros' => 200000000,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
        ], $user);
        $voided = $payments->record($irrigation->project_key, $irrigationOpportunity->id, [
            'amount_micros' => 100000000,
            'currency' => 'USD',
            'payment_method' => 'alipay',
        ], $user);
        $payments->record($irrigation->project_key, $irrigationOpportunity->id, [
            'amount_micros' => 50000000,
            'currency' => 'CNY',
            'payment_method' => 'alibaba',
        ], $user);
        $payments->void($irrigation->project_key, $voided->id, 'Duplicate confirmation.', $user);

        $payments->record($bottle->project_key, $bottleOpportunity->id, [
            'amount_micros' => 300000000,
            'currency' => 'EUR',
            'payment_method' => 'paypal',
        ], $user);

        $irrigationTotals = $received->totalsForProjectCompanies($irrigation->id, [$company->id]);
        $this->assertSame([
            ['currency' => 'CNY', 'amount_micros' => 50000000, 'receipt_count' => 1],
            ['currency' => 'USD', 'amount_micros' => 200000000, 'receipt_count' => 1],
        ], $irrigationTotals[$company->id]);

        $projectTotals = $received->totalsForCompanyProjects($company->id, [$irrigation->id, $bottle->id]);
        $this->assertSame(200000000, collect($projectTotals[$irrigation->id])->firstWhere('currency', 'USD')['amount_micros']);
        $this->assertSame(300000000, collect($projectTotals[$bottle->id])->firstWhere('currency', 'EUR')['amount_micros']);

        $this->assertSame(0, BusinessProjectCompany::query()
            ->where('business_project_id', $irrigation->id)
            ->where('company_id', $company->id)
            ->value('lifetime_revenue_micros'));

        $this->actingAs($user)
            ->get('/projects/yby-irrigation/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Project/Index')
                ->where('relationships.0.company.id', $company->id)
                ->where('relationships.0.received_totals.0.currency', 'CNY')
                ->where('relationships.0.received_totals.0.amount_micros', 50000000)
                ->where('relationships.0.received_totals.1.currency', 'USD')
                ->where('relationships.0.received_totals.1.amount_micros', 200000000));

        $this->actingAs($user)
            ->get('/customers/companies/'.$company->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Companies/Show')
                ->where('projectRelationships', fn ($rows): bool => collect($rows)->contains(
                    fn ($row): bool => $row['project']['key'] === 'yby-irrigation'
                        && collect($row['received_totals'])->contains(
                            fn ($total): bool => $total['currency'] === 'USD'
                                && $total['amount_micros'] === 200000000
                        )
                )));
    }

    private function truth(): array
    {
        $role = Role::create(['key' => 'revenue-truth-customer-reader', 'label' => 'Revenue Truth Customer Reader']);
        $permission = Permission::firstOrCreate(['key' => 'customers.view'], ['label' => 'customers.view']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);

        $irrigation = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $bottle = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);
        $company = Company::create(['name' => 'Revenue Truth Customer', 'status' => 'prospect', 'identity_status' => 'verified']);

        foreach ([$irrigation, $bottle] as $project) {
            BusinessProjectCompany::create([
                'business_project_id' => $project->id,
                'company_id' => $company->id,
                'owner_id' => $user->id,
                'relationship_status' => 'customer',
                'inquiry_count' => 0,
                'lifetime_revenue_micros' => 0,
            ]);
        }

        return [$user, $irrigation, $bottle, $company];
    }
}
