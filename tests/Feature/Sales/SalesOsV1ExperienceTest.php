<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Infrastructure\Models\SalesFollowUp;
use App\Modules\Sales\Infrastructure\Models\SalesFollowUpAttachment;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesOsV1ExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_workspace_is_project_scoped_and_permission_gated(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $viewer = $this->userWith(['sales.view']);
        $blocked = User::factory()->create(['is_active' => true]);

        $this->actingAs($viewer)->get('/projects/yby-irrigation/sales')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Index')
                ->where('project.key', 'yby-irrigation')
                ->where('metrics.open', 0)
                ->has('opportunities', 0));

        $this->actingAs($blocked)->get('/projects/yby-irrigation/sales')->assertForbidden();
    }

    public function test_operator_can_record_and_void_payment_receipt_from_opportunity_detail(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $operator = $this->userWith(['sales.view', 'sales.operate']);
        $viewer = $this->userWith(['sales.view']);
        $company = Company::create(['name' => 'Paid Customer', 'status' => 'prospect', 'identity_status' => 'verified']);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $operator->id,
            'title' => 'Payment receipt experience test',
            'currency' => 'USD',
            'expected_value_micros' => 1000000000,
        ], $operator);

        $this->actingAs($viewer)
            ->patch("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/receivable", [
                'source' => 'manual',
                'amount_micros' => 1000000000,
                'currency' => 'USD',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts", [
                'amount_micros' => 250000000,
                'currency' => 'USD',
                'received_at' => '2026-09-20 12:00:00',
                'payment_method' => 'bank_transfer',
            ])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts", [
                'amount_micros' => 250000000,
                'currency' => 'USD',
                'received_at' => '2026-09-20 12:00:00',
                'payment_method' => 'alipay',
                'provider_reference' => 'ALI-20260920-001',
                'evidence_reference' => 'sales-group-confirmation.png',
                'note' => '25% deposit confirmed.',
            ])
            ->assertRedirect();

        $receipt = SalesPaymentReceipt::query()->firstOrFail();
        $this->assertSame($operator->id, $receipt->recorded_by);

        $this->actingAs($operator)
            ->patch("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/receivable", [
                'source' => 'manual',
                'amount_micros' => 1000000000,
                'currency' => 'USD',
                'note' => 'Final receivable confirmed.',
            ])
            ->assertRedirect();

        $this->actingAs($operator)
            ->get("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Show')
                ->where('payment_receipts.0.id', $receipt->id)
                ->where('payment_receipts.0.amount_micros', 250000000)
                ->where('payment_receipts.0.payment_method', 'alipay')
                ->where('payment_receipts.0.recorded_by.name', $operator->name)
                ->where('payment_totals.0.currency', 'USD')
                ->where('payment_totals.0.amount_micros', 250000000)
                ->where('payment_totals.0.receipt_count', 1)
                ->where('receivable.status', 'partial')
                ->where('receivable.amount_micros', 1000000000)
                ->where('receivable.received_micros', 250000000)
                ->where('receivable.outstanding_micros', 750000000)
                ->where('receivable.source', 'manual')
                ->where('receivable.confirmed_by.name', $operator->name));

        $this->actingAs($operator)
            ->patch("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts/{$receipt->id}/void", [
                'void_reason' => 'Duplicate confirmation.',
            ])
            ->assertRedirect();

        $receipt->refresh();
        $this->assertSame('voided', $receipt->status);
        $this->assertSame('Duplicate confirmation.', $receipt->void_reason);

        $this->actingAs($operator)
            ->get("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_receipts.0.status', 'voided')
                ->where('payment_receipts.0.void_reason', 'Duplicate confirmation.')
                ->has('payment_totals', 0)
                ->where('receivable.status', 'unpaid')
                ->where('receivable.received_micros', 0)
                ->where('receivable.outstanding_micros', 1000000000));
    }

    public function test_receipt_ledger_is_project_scoped_filterable_and_exposes_audit_trail(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $otherProject = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);
        $operator = $this->userWith(['sales.view', 'sales.operate']);
        $company = Company::create(['name' => 'Ledger Customer', 'status' => 'prospect', 'identity_status' => 'verified']);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $operator->id,
            'title' => 'Ledger opportunity',
            'currency' => 'USD',
            'expected_value_micros' => 500000000,
        ], $operator);
        $otherOpportunity = $sales->create($otherProject->project_key, [
            'company_id' => $company->id,
            'owner_id' => $operator->id,
            'title' => 'Other project opportunity',
            'currency' => 'USD',
            'expected_value_micros' => 100000000,
        ], $operator);

        $this->actingAs($operator)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts", [
                'amount_micros' => 120000000,
                'currency' => 'USD',
                'received_at' => '2026-09-21 09:00:00',
                'payment_method' => 'bank_transfer',
                'provider_reference' => 'BANK-LEDGER-001',
            ])->assertRedirect();
        $usdReceipt = SalesPaymentReceipt::query()->where('currency', 'USD')->where('business_project_id', $project->id)->firstOrFail();

        $this->actingAs($operator)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts", [
                'amount_micros' => 800000000,
                'currency' => 'CNY',
                'received_at' => '2026-09-21 10:00:00',
                'payment_method' => 'alipay',
                'evidence_reference' => 'sales-group-confirmation.png',
            ])->assertRedirect();

        $this->actingAs($operator)
            ->post("/projects/{$otherProject->project_key}/sales/opportunities/{$otherOpportunity->id}/payment-receipts", [
                'amount_micros' => 999000000,
                'currency' => 'USD',
                'received_at' => '2026-09-21 11:00:00',
                'payment_method' => 'bank_transfer',
            ])->assertRedirect();

        $this->actingAs($operator)
            ->patch("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/payment-receipts/{$usdReceipt->id}/void", [
                'void_reason' => 'Duplicate bank confirmation.',
            ])->assertRedirect();

        $this->actingAs($operator)
            ->get("/projects/{$project->project_key}/sales/receipts?date_from=2026-09-21&date_to=2026-09-21")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Receipts')
                ->where('project.key', 'yby-irrigation')
                ->where('filters.date_from', '2026-09-21')
                ->where('filters.date_to', '2026-09-21')
                ->has('receipts', 2)
                ->where('receipts.0.currency', 'CNY')
                ->where('receipts.0.status', 'confirmed')
                ->where('receipts.0.company.name', 'Ledger Customer')
                ->where('receipts.0.opportunity.opportunity_number', $opportunity->opportunity_number)
                ->where('receipts.0.audit_events.0.action', 'sales.payment_receipt.recorded')
                ->where('receipts.0.audit_events.0.actor_name', $operator->name)
                ->where('receipts.1.id', $usdReceipt->id)
                ->where('receipts.1.status', 'voided')
                ->where('receipts.1.void_reason', 'Duplicate bank confirmation.')
                ->has('receipts.1.audit_events', 2)
                ->where('receipts.1.audit_events.0.action', 'sales.payment_receipt.recorded')
                ->where('receipts.1.audit_events.1.action', 'sales.payment_receipt.voided')
                ->where('receipts.1.audit_events.1.actor_name', $operator->name)
                ->has('confirmed_totals', 1)
                ->where('confirmed_totals.0.currency', 'CNY')
                ->where('confirmed_totals.0.amount_micros', 800000000));

        $this->actingAs($operator)
            ->get("/projects/{$project->project_key}/sales/receipts?date_from=2026-09-21&date_to=2026-09-21&status=confirmed&currency=CNY")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('receipts', 1)
                ->where('receipts.0.currency', 'CNY')
                ->where('receipts.0.status', 'confirmed')
                ->where('confirmed_totals.0.amount_micros', 800000000));
    }

    public function test_roadmap_follow_up_upload_is_permission_gated_and_attachment_read_is_project_scoped(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $otherProject = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);
        $operator = $this->userWith(['sales.view', 'sales.operate']);
        $viewer = $this->userWith(['sales.view']);
        $company = Company::create(['name' => 'Roadmap Experience Customer', 'status' => 'prospect', 'identity_status' => 'verified']);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $operator->id,
            'title' => 'Roadmap experience opportunity',
            'currency' => 'USD',
            'pipeline_stage' => 'qualified',
        ], $operator);
        $otherOpportunity = $sales->create($otherProject->project_key, [
            'company_id' => $company->id,
            'owner_id' => $operator->id,
            'title' => 'Other project roadmap opportunity',
            'currency' => 'USD',
            'pipeline_stage' => 'discovery',
        ], $operator);

        $this->actingAs($viewer)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/follow-ups", [
                'stage_key' => 'qualified',
                'channel' => 'whatsapp',
                'summary' => 'Viewer must not be able to write this note.',
            ])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}/follow-ups", [
                'stage_key' => 'qualified',
                'channel' => 'whatsapp',
                'outcome' => '需求确认',
                'summary' => 'Customer confirmed 16mm quick-connect requirements.',
                'next_plan' => 'Prepare solution drawing and PI draft.',
                'next_follow_up_at' => '2026-09-25 10:00:00',
                'attachments' => [
                    UploadedFile::fake()->image('site-photo.jpg', 800, 600),
                ],
            ])
            ->assertRedirect();

        $followUp = SalesFollowUp::query()->firstOrFail();
        $attachment = SalesFollowUpAttachment::query()->firstOrFail();

        $this->assertSame('qualified', $followUp->stage_key);
        $this->assertSame('Prepare solution drawing and PI draft.', $followUp->next_plan);
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($viewer)
            ->get("/projects/{$project->project_key}/sales/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Show')
                ->where('roadmap.current_stage', 'qualified')
                ->where('roadmap.current_label', '已确认需求')
                ->where('opportunity.follow_ups.0.stage_key', 'qualified')
                ->where('opportunity.follow_ups.0.next_plan', 'Prepare solution drawing and PI draft.')
                ->has('opportunity.follow_ups.0.attachments', 1)
                ->where('opportunity.follow_ups.0.attachments.0.name', 'site-photo.jpg'));

        $attachmentUrl = route('sales.opportunities.follow-ups.attachments.show', [
            'projectKey' => $project->project_key,
            'opportunity' => $opportunity->id,
            'followUp' => $followUp->id,
            'attachment' => $attachment->id,
        ], false);

        $this->actingAs($viewer)
            ->get($attachmentUrl)
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('sales.opportunities.follow-ups.attachments.show', [
                'projectKey' => $otherProject->project_key,
                'opportunity' => $otherOpportunity->id,
                'followUp' => $followUp->id,
                'attachment' => $attachment->id,
            ], false))
            ->assertNotFound();
    }

    private function userWith(array $keys): User
    {
        $role = Role::create(['key' => 'sales-test-'.uniqid(), 'label' => 'Sales Test']);
        $permissions = collect($keys)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role->id);

        return $user;
    }
}
