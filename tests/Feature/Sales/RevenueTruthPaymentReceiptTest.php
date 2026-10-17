<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class RevenueTruthPaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_receipt_records_actual_cash_without_fabricating_sales_or_customer_revenue(): void
    {
        [$project, $owner, $company, $relationship, $opportunity] = $this->truth();
        $receipts = $this->app->make(PaymentReceiptService::class);

        $receipt = $receipts->record($project->project_key, $opportunity->id, [
            'amount_micros' => 300000000,
            'currency' => 'USD',
            'received_at' => '2026-09-20 10:30:00',
            'payment_method' => 'bank_transfer',
            'provider_reference' => 'BANK-REF-001',
            'evidence_reference' => 'group-confirmation-20260920.png',
            'note' => '30% deposit confirmed in sales group.',
        ], $owner);

        $this->assertStringStartsWith('YBY-PAY-', $receipt->receipt_number);
        $this->assertSame($project->id, $receipt->business_project_id);
        $this->assertSame($opportunity->id, $receipt->opportunity_id);
        $this->assertSame($company->id, $receipt->company_id);
        $this->assertSame($owner->id, $receipt->recorded_by);
        $this->assertSame(300000000, $receipt->amount_micros);
        $this->assertSame('USD', $receipt->currency);
        $this->assertSame('bank_transfer', $receipt->payment_method);
        $this->assertSame('manual', $receipt->entry_source);
        $this->assertSame('confirmed', $receipt->status);
        $this->assertSame('BANK-REF-001', $receipt->provider_reference);
        $this->assertSame('group-confirmation-20260920.png', $receipt->evidence_reference);

        $opportunity->refresh();
        $relationship->refresh();
        $this->assertSame('open', $opportunity->status, 'Recording cash must not silently mutate sales status.');
        $this->assertSame(1000000000, $opportunity->expected_value_micros);
        $this->assertSame(0, $relationship->lifetime_revenue_micros, 'R1 establishes cash truth only; customer aggregate is a later stage.');
    }

    public function test_multiple_receipts_accumulate_by_currency_and_voided_receipts_drop_out_of_confirmed_totals(): void
    {
        [$project, $owner, , , $opportunity] = $this->truth();
        $receipts = $this->app->make(PaymentReceiptService::class);

        $deposit = $receipts->record($project->project_key, $opportunity->id, [
            'amount_micros' => 300000000,
            'currency' => 'USD',
            'payment_method' => 'alipay',
            'received_at' => '2026-09-20 10:00:00',
        ], $owner);
        $balance = $receipts->record($project->project_key, $opportunity->id, [
            'amount_micros' => 700000000,
            'currency' => 'USD',
            'payment_method' => 'alibaba',
            'received_at' => '2026-09-21 10:00:00',
        ], $owner);
        $receipts->record($project->project_key, $opportunity->id, [
            'amount_micros' => 50000000,
            'currency' => 'CNY',
            'payment_method' => 'bank_transfer',
            'received_at' => '2026-09-21 11:00:00',
        ], $owner);

        $totals = collect($receipts->confirmedTotalsForOpportunity($project->project_key, $opportunity->id))->keyBy('currency');
        $this->assertSame(1000000000, $totals['USD']['amount_micros']);
        $this->assertSame(2, $totals['USD']['receipt_count']);
        $this->assertSame(50000000, $totals['CNY']['amount_micros']);
        $this->assertSame(1, $totals['CNY']['receipt_count']);

        $voided = $receipts->void($project->project_key, $balance->id, 'Duplicate group confirmation.', $owner);
        $this->assertSame('voided', $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($owner->id, $voided->voided_by);
        $this->assertSame('Duplicate group confirmation.', $voided->void_reason);

        $totals = collect($receipts->confirmedTotalsForOpportunity($project->project_key, $opportunity->id))->keyBy('currency');
        $this->assertSame(300000000, $totals['USD']['amount_micros']);
        $this->assertSame(1, $totals['USD']['receipt_count']);
        $this->assertSame(3, SalesPaymentReceipt::count(), 'Voiding preserves the audit record instead of deleting it.');

        $same = $receipts->void($project->project_key, $balance->id, 'Second void should be idempotent.', $owner);
        $this->assertSame($voided->id, $same->id);
        $this->assertSame('Duplicate group confirmation.', $same->void_reason, 'A second void must not rewrite historical reason.');
        $this->assertSame($deposit->id, $receipts->listForOpportunity($project->project_key, $opportunity->id)->last()->id);
    }

    public function test_receipts_are_project_scoped_and_invalid_payment_truth_is_rejected(): void
    {
        [$project, $owner, , , $opportunity] = $this->truth();
        $receipts = $this->app->make(PaymentReceiptService::class);

        foreach ([
            ['amount_micros' => 0, 'currency' => 'USD', 'payment_method' => 'bank_transfer'],
            ['amount_micros' => 1000000, 'currency' => 'US', 'payment_method' => 'bank_transfer'],
            ['amount_micros' => 1000000, 'currency' => 'USD', 'payment_method' => 'crypto'],
        ] as $invalid) {
            try {
                $receipts->record($project->project_key, $opportunity->id, $invalid, $owner);
                $this->fail('Invalid receipt truth should be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $other = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);

        $this->expectException(ModelNotFoundException::class);
        $receipts->record($other->project_key, $opportunity->id, [
            'amount_micros' => 1000000,
            'currency' => 'USD',
            'payment_method' => 'paypal',
        ], $owner);
    }

    private function truth(): array
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $owner = User::factory()->create(['name' => 'Revenue Recorder', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect', 'identity_status' => 'verified']);
        $relationship = BusinessProjectCompany::create([
            'business_project_id' => $project->id,
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'relationship_status' => 'prospect',
            'lifetime_revenue_micros' => 0,
        ]);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'title' => 'Revenue truth test opportunity',
            'currency' => 'USD',
            'expected_value_micros' => 1000000000,
        ], $owner);

        return [$project, $owner, $company, $relationship, $opportunity];
    }
}
