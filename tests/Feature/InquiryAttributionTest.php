<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Inquiries\Application\InquiryAttributionService;
use App\Modules\Inquiries\Infrastructure\Models\CaseAttribution;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Inquiries\Infrastructure\Repositories\EloquentInquiryCaseRepository;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\BusinessProjects\Infrastructure\Repositories\EloquentBusinessProjectRepository;
use App\Modules\Traffic\Application\TrafficService;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class InquiryAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        if (! Schema::hasTable('sales_opportunities')) {
            (require $root.'/database/migrations/2026_09_17_101500_create_sales_os_v1_tables.php')->up();
        }
        if (! Schema::hasTable('case_attributions')) {
            (require $root.'/database/migrations/2026_09_17_133000_create_case_attributions_table.php')->up();
        }
    }

    public function test_explicit_campaign_and_landing_evidence_resolves(): void
    {
        [$project, $campaign] = $this->trafficTruth();
        $case = $this->case($project, [
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => $campaign->name,
            'source_url' => 'https://example.test/products/a?utm_campaign=ignored',
        ]);
        LandingPage::create(['business_project_id' => $project->id, 'page_key' => 'product-a', 'name' => 'A', 'canonical_url' => 'https://example.test/products/a', 'path' => '/products/a', 'status' => 'active']);

        $result = $this->service()->resolveCase($case->id);

        $this->assertSame('resolved', $result['attribution_state']);
        $this->assertSame($campaign->id, $result['traffic_campaign_id']);
        $this->assertSame('matched', $result['landing_page_match_state']);
        $this->assertSame('google', $result['evidence']['first_touch']['utm_source']);
        $this->assertSame('website', $result['evidence']['last_touch']['source_channel']);
    }

    public function test_insufficient_or_ambiguous_evidence_is_preserved(): void
    {
        [$project] = $this->trafficTruth();
        $partial = $this->case($project, ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'missing']);
        $unresolved = $this->case($project, []);

        $this->assertSame('partial', $this->service()->resolveCase($partial->id, false)['attribution_state']);
        $this->assertSame('unresolved', $this->service()->resolveCase($unresolved->id, false)['attribution_state']);
    }

    public function test_apply_is_idempotent_and_does_not_create_crm_truth(): void
    {
        [$project, $campaign] = $this->trafficTruth();
        $case = $this->case($project, ['utm_campaign' => $campaign->external_campaign_id]);
        $service = $this->service();

        $service->resolveCase($case->id);
        $service->resolveCase($case->id);

        $this->assertSame(1, CaseAttribution::count());
        $this->assertSame(1, InquiryCase::count());
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sales_opportunities', 0);
    }

    private function trafficTruth(): array
    {
        $project = BusinessProject::create(['project_key' => 'attr-test', 'name' => 'Attribution', 'status' => 'active']);
        $account = TrafficSourceAccount::create(['business_project_id' => $project->id, 'provider' => 'google_ads', 'external_account_id' => 'acc-1', 'name' => 'Google', 'status' => 'active']);
        $campaign = TrafficCampaign::create(['traffic_source_account_id' => $account->id, 'external_campaign_id' => 'camp-1', 'name' => 'Spring Campaign', 'status' => 'enabled']);
        return [$project, $campaign];
    }

    private function case(BusinessProject $project, array $attribution): InquiryCase
    {
        return InquiryCase::create([
            'case_number' => 'CASE-'.str_pad((string) (InquiryCase::count() + 1), 4, '0', STR_PAD_LEFT),
            'business_project_id' => $project->id, 'title' => 'Attribution test', 'source_channel' => 'website',
            'first_inbound_at' => now(), 'last_inbound_at' => now(), 'attribution' => $attribution,
            'source_payload' => ['source_metadata' => ['source_page' => 'Contact page']],
        ]);
    }

    private function service(): InquiryAttributionService
    {
        return new InquiryAttributionService(
            new EloquentInquiryCaseRepository(),
            new BusinessProjectService(new EloquentBusinessProjectRepository()),
            new TrafficService(new EloquentTrafficRepository()),
        );
    }
}
