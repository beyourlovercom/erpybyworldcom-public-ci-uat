<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Application\ReceivableStatusService;
use App\Modules\Sales\Application\SalesOpportunityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class RevenueTruthReceivableStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivable_stays_pending_until_explicitly_confirmed_and_then_tracks_cash_states(): void
    {
        [$project, $owner, , , $opportunity] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $receivables = $this->app->make(ReceivableStatusService::class);
        $payments = $this->app->make(PaymentReceiptService::class);

        $sales->createQuote($project->project_key, $opportunity->id, [
            'currency' => 'USD',
            'subtotal_micros' => 900000000,
        ], $owner);

        $pending = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('pending_receivable', $pending['status']);
        $this->assertNull($pending['amount_micros']);
        $this->assertSame(0, $pending['received_micros']);

        $receivables->confirmManual(
            $project->project_key,
            $opportunity->id,
            1000000000,
            'usd',
            'Final commercial amount confirmed in group.',
            $owner,
        );

        $unpaid = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('unpaid', $unpaid['status']);
        $this->assertSame(1000000000, $unpaid['amount_micros']);
        $this->assertSame('USD', $unpaid['currency']);
        $this->assertSame('manual', $unpaid['source']);
        $this->assertSame($owner->id, $unpaid['confirmed_by']['id']);
        $this->assertSame(1000000000, $unpaid['outstanding_micros']);

        $payments->record($project->project_key, $opportunity->id, [
            'amount_micros' => 300000000,
            'currency' => 'USD',
            'payment_method' => 'alipay',
        ], $owner);
        $payments->record($project->project_key, $opportunity->id, [
            'amount_micros' => 50000000,
            'currency' => 'CNY',
            'payment_method' => 'bank_transfer',
        ], $owner);

        $partial = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('partial', $partial['status']);
        $this->assertSame(300000000, $partial['received_micros']);
        $this->assertSame(700000000, $partial['outstanding_micros']);
        $this->assertSame('CNY', $partial['other_currency_totals'][0]['currency']);
        $this->assertSame(50000000, $partial['other_currency_totals'][0]['amount_micros']);

        $payments->record($project->project_key, $opportunity->id, [
            'amount_micros' => 700000000,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
        ], $owner);

        $paid = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(1000000000, $paid['received_micros']);
        $this->assertSame(0, $paid['outstanding_micros']);
        $this->assertSame(0, $paid['overpaid_micros']);

        $payments->record($project->project_key, $opportunity->id, [
            'amount_micros' => 100000000,
            'currency' => 'USD',
            'payment_method' => 'paypal',
        ], $owner);

        $overpaid = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('overpaid', $overpaid['status']);
        $this->assertSame(1100000000, $overpaid['received_micros']);
        $this->assertSame(100000000, $overpaid['overpaid_micros']);
    }

    public function test_quote_can_be_explicitly_selected_as_receivable_truth_but_never_auto_selected(): void
    {
        [$project, $owner, $company, , $opportunity] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $receivables = $this->app->make(ReceivableStatusService::class);

        $quote = $sales->createQuote($project->project_key, $opportunity->id, [
            'currency' => 'USD',
            'subtotal_micros' => 800000000,
            'discount_micros' => 50000000,
        ], $owner);

        $this->assertSame('pending_receivable', $receivables->summary($project->project_key, $opportunity->id)['status']);

        $confirmed = $receivables->confirmFromQuote(
            $project->project_key,
            $opportunity->id,
            $quote->id,
            'Customer confirmed this quote.',
            $owner,
        );

        $this->assertSame(750000000, $confirmed->receivable_amount_micros);
        $this->assertSame('USD', $confirmed->receivable_currency);
        $this->assertSame('quote', $confirmed->receivable_source);
        $this->assertSame($quote->id, $confirmed->receivable_quote_id);

        $summary = $receivables->summary($project->project_key, $opportunity->id);
        $this->assertSame('unpaid', $summary['status']);
        $this->assertSame($quote->quote_number, $summary['quote_number']);

        $otherOpportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'title' => 'Other opportunity',
            'currency' => 'USD',
        ], $owner);

        $this->expectException(ModelNotFoundException::class);
        $receivables->confirmFromQuote(
            $project->project_key,
            $otherOpportunity->id,
            $quote->id,
            null,
            $owner,
        );
    }

    public function test_voided_receipt_recalculates_status_and_lost_opportunity_cannot_set_receivable(): void
    {
        [$project, $owner, , , $opportunity] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $receivables = $this->app->make(ReceivableStatusService::class);
        $payments = $this->app->make(PaymentReceiptService::class);

        $receivables->confirmManual($project->project_key, $opportunity->id, 500000000, 'USD', null, $owner);
        $receipt = $payments->record($project->project_key, $opportunity->id, [
            'amount_micros' => 500000000,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
        ], $owner);

        $this->assertSame('paid', $receivables->summary($project->project_key, $opportunity->id)['status']);

        $payments->void($project->project_key, $receipt->id, 'Bank reversed the transfer.', $owner);
        $this->assertSame('unpaid', $receivables->summary($project->project_key, $opportunity->id)['status']);

        $sales->markLost($project->project_key, $opportunity->id, 'cancelled', null, $owner);

        $this->expectException(InvalidArgumentException::class);
        $receivables->confirmManual($project->project_key, $opportunity->id, 500000000, 'USD', null, $owner);
    }

    private function truth(): array
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $owner = User::factory()->create(['name' => 'Revenue Owner', 'is_active' => true]);
        $company = Company::create(['name' => 'Revenue Customer', 'status' => 'prospect', 'identity_status' => 'verified']);
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
            'title' => 'Receivable status test',
            'currency' => 'USD',
            'expected_value_micros' => 1200000000,
        ], $owner);

        return [$project, $owner, $company, $relationship, $opportunity];
    }
}
