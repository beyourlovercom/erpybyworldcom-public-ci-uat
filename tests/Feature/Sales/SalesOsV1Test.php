<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Infrastructure\Models\SalesFollowUp;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalesOsV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_inquiry_creates_one_idempotent_opportunity(): void
    {
        [$project, $owner, $company, $case] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $input = [
            'case_id' => $case->id, 'company_id' => $company->id, 'owner_id' => $owner->id,
            'title' => 'Tanzania wholesale drip irrigation', 'currency' => 'USD',
            'expected_value_micros' => 1250000000, 'pipeline_stage' => 'qualified',
        ];

        $first = $sales->create($project->project_key, $input, $owner);
        $second = $sales->create($project->project_key, $input, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SalesOpportunity::count());
        $this->assertSame('qualified', $first->pipeline_stage);
        $this->assertSame(3000, $first->probability_bps);
        $this->assertSame($case->attribution, $first->attribution);
    }

    public function test_follow_up_quote_and_won_keep_revenue_truth_separate(): void
    {
        [$project, $owner, $company, $case, $relationship] = $this->truth(withRelationship: true);
        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'case_id' => $case->id, 'company_id' => $company->id, 'owner_id' => $owner->id,
            'title' => 'Kenya distributor opportunity', 'currency' => 'USD', 'expected_value_micros' => 500000000,
        ], $owner);

        $sales->addFollowUp($project->project_key, $opportunity->id, [
            'channel' => 'whatsapp', 'summary' => 'Buyer confirmed specification.',
            'next_follow_up_at' => now()->addDay(),
        ], $owner);
        $quote = $sales->createQuote($project->project_key, $opportunity->id, [
            'currency' => 'USD', 'subtotal_micros' => 500000000,
            'discount_micros' => 25000000, 'tax_micros' => 0,
        ], $owner);
        $won = $sales->markWon($project->project_key, $opportunity->id, $owner);

        $this->assertSame(1, SalesFollowUp::count());
        $this->assertSame(475000000, $quote->total_micros);
        $this->assertSame('won', $won->status);
        $this->assertSame(10000, $won->probability_bps);
        $relationship->refresh();
        $this->assertSame('customer', $relationship->relationship_status);
        $this->assertNotNull($relationship->last_won_at);
        $this->assertSame(0, $relationship->lifetime_revenue_micros, 'Won opportunity must not fabricate realized revenue.');
    }

    public function test_quote_versions_and_loss_reason_are_explicit_truth(): void
    {
        [$project, $owner, $company] = $this->truth();
        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id, 'owner_id' => $owner->id,
            'title' => 'Bottle project opportunity', 'currency' => 'USD',
        ], $owner);

        $sales->createQuote($project->project_key, $opportunity->id, ['subtotal_micros' => 100000000], $owner);
        $sales->createQuote($project->project_key, $opportunity->id, ['subtotal_micros' => 90000000], $owner);
        $lost = $sales->markLost($project->project_key, $opportunity->id, 'price', 'Budget gap', $owner);

        $this->assertSame([1, 2], SalesQuote::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame('lost', $lost->status);
        $this->assertSame('price', $lost->lost_reason_code);
        $this->assertSame(0, $lost->probability_bps);
    }

    private function truth(bool $withRelationship = false): array
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $owner = User::factory()->create(['name' => 'Sales Owner', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme Irrigation', 'status' => 'prospect', 'identity_status' => 'verified']);
        $case = InquiryCase::create([
            'case_number' => 'YBY-IRR-260917-ABC123', 'business_project_id' => $project->id, 'company_id' => $company->id,
            'owner_id' => $owner->id, 'title' => 'Wholesale inquiry', 'source_channel' => 'website',
            'source_connection_key' => 'ybyirrigation-com', 'source_external_id' => 'lead-1',
            'source_payload_hash' => str_repeat('a', 64), 'case_status' => 'open', 'case_stage' => 'qualified',
            'handling_state' => 'in_progress', 'identity_resolution_state' => 'resolved',
            'first_inbound_at' => now(), 'last_inbound_at' => now(), 'attribution' => ['utm_source' => 'google'],
        ]);
        if (! $withRelationship) return [$project, $owner, $company, $case];
        $relationship = BusinessProjectCompany::create([
            'business_project_id' => $project->id, 'company_id' => $company->id, 'owner_id' => $owner->id,
            'relationship_status' => 'prospect', 'lifetime_revenue_micros' => 0,
        ]);
        return [$project, $owner, $company, $case, $relationship];
    }
}
