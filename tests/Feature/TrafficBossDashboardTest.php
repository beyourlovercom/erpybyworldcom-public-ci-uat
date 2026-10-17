<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Inquiries\Infrastructure\Models\CaseAttribution;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use App\Modules\Sales\Infrastructure\Models\SalesQuote;
use App\Modules\Shell\Application\ErpShellNavigation;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficInquiryDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TrafficBossDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_marketing_viewer_sees_project_level_boss_dashboard_without_fake_qualification(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $account = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'google_ads',
            'external_account_id' => '5020248458',
            'name' => '亿碧源-灌溉',
            'currency_code' => 'CNY',
            'account_timezone' => 'Asia/Shanghai',
            'last_synced_at' => '2026-09-14 08:00:00',
        ]);
        $campaign = TrafficCampaign::create([
            'traffic_source_account_id' => $account->id,
            'external_campaign_id' => '24023703280',
            'name' => 'YBY-Search-IRR-SOLUTION-Tanzania',
            'status' => 'enabled',
        ]);
        TrafficDailyMetric::create(['traffic_campaign_id' => $campaign->id, 'metric_date' => '2026-09-12', 'impressions' => 300, 'clicks' => 50, 'cost_micros' => 400000000, 'provider_conversions' => 12]);
        TrafficDailyMetric::create(['traffic_campaign_id' => $campaign->id, 'metric_date' => '2026-09-13', 'impressions' => 402, 'clicks' => 59, 'cost_micros' => 480684249, 'provider_conversions' => 15]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-12', 'source_type' => 'google_ads_form_lead', 'inquiries' => 11, 'qualified_inquiries' => 7]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-13', 'source_type' => 'google_ads_form_lead', 'inquiries' => 15, 'qualified_inquiries' => 9]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-13', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 1, 'qualified_inquiries' => 1]);
        $company = Company::create(['name' => 'Growth UAT Co', 'status' => 'prospect', 'identity_status' => 'verified']);
        $case1 = InquiryCase::create(['case_number' => 'GROWTH-1', 'business_project_id' => $project->id, 'company_id' => $company->id, 'title' => 'Lead 1', 'source_channel' => 'website', 'case_stage' => 'qualified', 'identity_resolution_state' => 'resolved', 'first_inbound_at' => '2026-09-12 09:00:00', 'last_inbound_at' => '2026-09-12 09:00:00']);
        $case2 = InquiryCase::create(['case_number' => 'GROWTH-2', 'business_project_id' => $project->id, 'company_id' => $company->id, 'title' => 'Lead 2', 'source_channel' => 'website', 'case_stage' => 'qualified', 'identity_resolution_state' => 'resolved', 'first_inbound_at' => '2026-09-13 09:00:00', 'last_inbound_at' => '2026-09-13 09:00:00']);
        InquiryCase::create(['case_number' => 'GROWTH-3', 'business_project_id' => $project->id, 'title' => 'Lead 3', 'source_channel' => 'website', 'case_stage' => 'new', 'identity_resolution_state' => 'unresolved', 'first_inbound_at' => '2026-09-13 10:00:00', 'last_inbound_at' => '2026-09-13 10:00:00']);
        InquiryCase::create(['case_number' => 'GROWTH-HISTORY-1', 'business_project_id' => $project->id, 'title' => 'Historical lead', 'source_channel' => 'website', 'case_stage' => 'new', 'identity_resolution_state' => 'unresolved', 'first_inbound_at' => '2026-08-01 10:00:00', 'last_inbound_at' => '2026-08-01 10:00:00']);
        $landing = LandingPage::create(['business_project_id' => $project->id, 'page_key' => 'irrigation-wholesale', 'name' => 'Irrigation Wholesale', 'canonical_url' => 'https://ybyirrigation.com/lp/irrigation-wholesale/', 'path' => '/lp/irrigation-wholesale/', 'status' => 'active']);
        CaseAttribution::create(['case_id' => $case1->id, 'business_project_id' => $project->id, 'traffic_source_account_id' => $account->id, 'traffic_campaign_id' => $campaign->id, 'landing_page_id' => $landing->id, 'source_kind' => 'google_ads', 'attribution_state' => 'resolved', 'campaign_match_state' => 'matched', 'landing_page_match_state' => 'matched', 'evidence_hash' => str_repeat('a', 64)]);
        CaseAttribution::create(['case_id' => $case2->id, 'business_project_id' => $project->id, 'traffic_source_account_id' => $account->id, 'traffic_campaign_id' => $campaign->id, 'source_kind' => 'google_ads', 'attribution_state' => 'partial', 'campaign_match_state' => 'matched', 'landing_page_match_state' => 'unresolved', 'evidence_hash' => str_repeat('b', 64)]);
        $opp1 = SalesOpportunity::create(['opportunity_number' => 'GROWTH-OPP-1', 'business_project_id' => $project->id, 'case_id' => $case1->id, 'company_id' => $company->id, 'title' => 'Won opportunity', 'status' => 'won', 'pipeline_stage' => 'won', 'currency' => 'USD', 'expected_value_micros' => 100000000, 'probability_bps' => 10000, 'won_at' => '2026-09-13 11:00:00']);
        $opp1->forceFill(['created_at' => '2026-09-12 12:00:00', 'updated_at' => '2026-09-13 11:00:00'])->save();
        $opp2 = SalesOpportunity::create(['opportunity_number' => 'GROWTH-OPP-2', 'business_project_id' => $project->id, 'case_id' => $case2->id, 'company_id' => $company->id, 'title' => 'Quoted opportunity', 'status' => 'open', 'pipeline_stage' => 'quoted', 'currency' => 'USD', 'expected_value_micros' => 50000000, 'probability_bps' => 6000]);
        $opp2->forceFill(['created_at' => '2026-09-13 12:00:00', 'updated_at' => '2026-09-13 12:00:00'])->save();
        $quote = SalesQuote::create(['quote_number' => 'GROWTH-Q-1', 'opportunity_id' => $opp2->id, 'version' => 1, 'status' => 'draft', 'currency' => 'USD', 'subtotal_micros' => 50000000, 'total_micros' => 50000000]);
        $quote->forceFill(['created_at' => '2026-09-13 13:00:00', 'updated_at' => '2026-09-13 13:00:00'])->save();
        SalesPaymentReceipt::create(['receipt_number' => 'GROWTH-PAY-1', 'business_project_id' => $project->id, 'opportunity_id' => $opp1->id, 'company_id' => $company->id, 'amount_micros' => 30000000, 'currency' => 'USD', 'received_at' => '2026-09-12 15:00:00', 'payment_method' => 'bank_transfer', 'entry_source' => 'manual', 'status' => 'confirmed']);
        SalesPaymentReceipt::create(['receipt_number' => 'GROWTH-PAY-2', 'business_project_id' => $project->id, 'opportunity_id' => $opp1->id, 'company_id' => $company->id, 'amount_micros' => 20000000, 'currency' => 'USD', 'received_at' => '2026-09-13 15:00:00', 'payment_method' => 'alipay', 'entry_source' => 'manual', 'status' => 'confirmed']);
        SalesPaymentReceipt::create(['receipt_number' => 'GROWTH-PAY-VOID', 'business_project_id' => $project->id, 'opportunity_id' => $opp1->id, 'company_id' => $company->id, 'amount_micros' => 10000000, 'currency' => 'USD', 'received_at' => '2026-09-13 16:00:00', 'payment_method' => 'alipay', 'entry_source' => 'manual', 'status' => 'voided', 'voided_at' => '2026-09-13 17:00:00', 'void_reason' => 'Duplicate confirmation']);

        $response = $this->actingAs($this->userWith(['marketing.view']))
            ->get('/projects/yby-irrigation/growth?date_from=2026-09-12&date_to=2026-09-13&dimension=campaign&value='.$campaign->id);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Traffic/BossDashboard')
            ->where('project.key', 'yby-irrigation')
            ->where('project.name', 'YBY Irrigation')
            ->where('summary.currency_code', 'CNY')
            ->where('summary.impressions', 702)
            ->where('summary.clicks', 109)
            ->where('summary.spend', '880.68')
            ->where('summary.inquiries', 3)
            ->where('summary.total_inquiries', 4)
            ->where('summary.qualified_inquiries', 2)
            ->has('weekly_comparison', 1)
            ->where('weekly_comparison.0.clicks', 109)
            ->where('weekly_comparison.0.inquiries', 3)
            ->where('weekly_comparison.0.spend', '880.68')
            ->where('weekly_comparison.0.won', 1)
            ->where('weekly_comparison.0.is_partial', true)
            ->where('weekly_comparison.0.period_start', '2026-09-12')
            ->where('weekly_comparison.0.period_end', '2026-09-13')
            ->where('time_comparison.version', 'boss-time-comparison-v2')
            ->where('time_comparison.compare_mode', 'previous')
            ->where('time_comparison.granularity', 'day')
            ->where('time_comparison.current_range.effective_from', '2026-09-12')
            ->where('time_comparison.current_range.effective_to', '2026-09-13')
            ->where('time_comparison.compare_range.date_from', '2026-09-10')
            ->where('time_comparison.compare_range.date_to', '2026-09-11')
            ->where('time_comparison.summary.current.clicks', 109)
            ->where('time_comparison.summary.current.inquiries', 3)
            ->where('time_comparison.summary.current.spend', '880.68')
            ->where('time_comparison.summary.current.won', 1)
            ->where('time_comparison.summary.current.received_totals.0.currency', 'USD')
            ->where('time_comparison.summary.current.received_totals.0.amount_micros', 50000000)
            ->where('time_comparison.summary.current.received_totals.0.receipt_count', 2)
            ->where('time_comparison.series.current.0.received_totals.0.amount_micros', 30000000)
            ->where('time_comparison.series.current.1.received_totals.0.amount_micros', 20000000)
            ->has('time_comparison.series.current', 2)
            ->where('summary.opportunities', 2)
            ->where('summary.quoted', 1)
            ->where('summary.won', 1)
            ->where('summary.won_value', '100.00')
            ->where('summary.won_currency_code', 'USD')
            ->where('summary.received_totals.0.currency', 'USD')
            ->where('summary.received_totals.0.amount_micros', 50000000)
            ->where('summary.received_totals.0.amount', '50.00')
            ->where('summary.received_totals.0.receipt_count', 2)
            ->where('summary.received', '50.00')
            ->where('summary.received_currency_code', 'USD')
            ->where('summary.revenue', null)
            ->where('summary.cost_per_inquiry', '293.56')
            ->has('funnel', 6)
            ->where('funnel.0.label', '流量')
            ->where('funnel.1.label', '询盘')
            ->where('funnel.2.label', '有效询盘')
            ->where('funnel.3.label', '商机')
            ->where('funnel.4.label', '已报价')
            ->where('funnel.5.label', '成交')
            ->where('metrics_contract.won_value_is_revenue', false)
            ->where('metrics_contract.received_is_revenue', false)
            ->where('metrics_contract.received_state', 'available_from_confirmed_payment_receipts')
            ->where('metrics_contract.revenue_state', 'recognition_not_defined')
            ->has('campaigns', 1)
            ->where('campaigns.0.name', 'YBY-Search-IRR-SOLUTION-Tanzania')
            ->where('campaigns.0.inquiries', 2)
            ->where('campaigns.0.opportunities', 2)
            ->where('campaigns.0.won', 1)
            ->has('landing_pages', 1)
            ->where('landing_pages.0.inquiries', 1)
            ->where('landing_pages.0.won', 1)
            ->where('attribution_quality.resolved', 1)
            ->where('attribution_quality.partial', 1)
            ->where('attribution_quality.unresolved', 1)
            ->where('attribution_quality.missing', 1)
            ->where('drilldown.dimension', 'campaign')
            ->where('drilldown.total', 2)
            ->has('drilldown.rows', 2)
            ->has('sources', 2)
            ->where('data_health.automatic_sync_enabled', false)
            ->where('data_health.data_to', '2026-09-13'));
    }

    public function test_boss_received_truth_keeps_currencies_separate_and_uses_received_at_for_coverage(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $company = Company::create(['name' => 'Mixed Currency Customer', 'status' => 'prospect', 'identity_status' => 'verified']);
        $opportunity = SalesOpportunity::create([
            'opportunity_number' => 'MIXED-OPP-1',
            'business_project_id' => $project->id,
            'company_id' => $company->id,
            'title' => 'Mixed receipt opportunity',
            'status' => 'open',
            'pipeline_stage' => 'negotiating',
            'currency' => 'USD',
            'expected_value_micros' => 0,
            'probability_bps' => 0,
        ]);

        SalesPaymentReceipt::create(['receipt_number' => 'MIXED-PAY-USD', 'business_project_id' => $project->id, 'opportunity_id' => $opportunity->id, 'company_id' => $company->id, 'amount_micros' => 120000000, 'currency' => 'USD', 'received_at' => '2026-09-20 09:00:00', 'payment_method' => 'bank_transfer', 'entry_source' => 'manual', 'status' => 'confirmed']);
        SalesPaymentReceipt::create(['receipt_number' => 'MIXED-PAY-CNY', 'business_project_id' => $project->id, 'opportunity_id' => $opportunity->id, 'company_id' => $company->id, 'amount_micros' => 800000000, 'currency' => 'CNY', 'received_at' => '2026-09-20 10:00:00', 'payment_method' => 'alipay', 'entry_source' => 'manual', 'status' => 'confirmed']);

        $this->actingAs($this->userWith(['marketing.view'], 'mixed-received-viewer'))
            ->get('/projects/yby-irrigation/growth?date_from=2026-09-20&date_to=2026-09-20&compare_mode=none')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.received_currency_code', 'MIXED')
                ->where('summary.received', null)
                ->where('summary.received_totals.0.currency', 'CNY')
                ->where('summary.received_totals.0.amount_micros', 800000000)
                ->where('summary.received_totals.1.currency', 'USD')
                ->where('summary.received_totals.1.amount_micros', 120000000)
                ->where('time_comparison.current_range.effective_from', '2026-09-20')
                ->where('time_comparison.current_range.effective_to', '2026-09-20')
                ->where('time_comparison.summary.current.received_totals.0.currency', 'CNY')
                ->where('time_comparison.summary.current.received_totals.1.currency', 'USD')
                ->where('time_comparison.series.current.0.received_totals.0.amount_micros', 800000000)
                ->where('time_comparison.series.current.0.received_totals.1.amount_micros', 120000000)
                ->where('data_health.data_from', '2026-09-20')
                ->where('data_health.data_to', '2026-09-20'));
    }

    public function test_project_root_uses_boss_dashboard_for_marketing_viewer(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $user = $this->userWith(['dashboard.view', 'marketing.view'], 'project-boss-home');

        $this->actingAs($user)
            ->get('/projects/yby-irrigation?date_from=2026-09-01&date_to=2026-09-13')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Traffic/BossDashboard')
                ->where('project.key', 'yby-irrigation')
                ->where('filters.date_from', '2026-09-01')
                ->where('filters.date_to', '2026-09-13'));
    }

    public function test_time_comparison_supports_gsc_style_ranges_and_granularity(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $user = $this->userWith(['marketing.view'], 'time-comparison-viewer');

        $this->actingAs($user)
            ->get('/projects/yby-irrigation/growth?date_from=2026-08-22&date_to=2026-09-20')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('time_comparison.granularity', 'week')
                ->where('time_comparison.compare_mode', 'previous')
                ->where('time_comparison.compare_range.date_from', '2026-07-23')
                ->where('time_comparison.compare_range.date_to', '2026-08-21'));

        $this->actingAs($user)
            ->get('/projects/yby-irrigation/growth?date_from=2025-09-21&date_to=2026-09-20&compare_mode=yoy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('time_comparison.granularity', 'month')
                ->where('time_comparison.compare_mode', 'yoy')
                ->where('time_comparison.compare_range.date_from', '2024-09-21')
                ->where('time_comparison.compare_range.date_to', '2025-09-20')
                ->where('time_comparison.summary.compare', null));

        $this->actingAs($user)
            ->get('/projects/yby-irrigation/growth?date_from=2023-01-01&date_to=2026-09-20&compare_mode=none')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('time_comparison.granularity', 'year')
                ->where('time_comparison.compare_mode', 'none')
                ->where('time_comparison.compare_range', null));

        $this->actingAs($user)
            ->get('/projects/yby-irrigation/growth?date_from=2026-09-01&date_to=2026-09-10&compare_mode=custom&compare_from=2026-08-01&compare_to=2026-08-10&granularity=day')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('time_comparison.granularity', 'day')
                ->where('time_comparison.compare_mode', 'custom')
                ->where('time_comparison.compare_range.date_from', '2026-08-01')
                ->where('time_comparison.compare_range.date_to', '2026-08-10'));
    }

    public function test_dashboard_requires_marketing_view_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/marketing/traffic')->assertForbidden();
    }

    public function test_project_navigation_exposes_project_scoped_traffic_child(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $user = $this->userWith(['marketing.view'], 'traffic-navigation-user');
        $navigation = app(ErpShellNavigation::class)->for($user);
        $project = collect($navigation)->firstWhere('key', 'project-yby-irrigation');
        $growth = collect($project['children'] ?? [])->firstWhere('key', 'project-yby-irrigation-growth');
        $traffic = collect($project['children'] ?? [])->firstWhere('key', 'project-yby-irrigation-traffic');

        $this->assertNull($growth);
        $this->assertSame('流量', $traffic['label']);
        $this->assertSame('/projects/yby-irrigation/traffic', $traffic['path']);
        $this->assertSame('traffic.project-dashboard', $traffic['route']);
    }

    public function test_dashboard_rejects_invalid_or_unbounded_date_ranges(): void
    {
        $user = $this->userWith(['marketing.view'], 'traffic-date-user');
        $this->actingAs($user)->get('/marketing/traffic?date_from=2026-09-20&date_to=2026-09-01')->assertStatus(422);
        $this->actingAs($user)->get('/marketing/traffic?date_from=2015-01-01&date_to=2026-09-01')->assertStatus(422);
        $this->actingAs($user)->get('/projects/yby-irrigation/growth?dimension=bad&value=1')->assertStatus(422);
        $this->actingAs($user)->get('/projects/yby-irrigation/growth?compare_mode=bad')->assertStatus(422);
        $this->actingAs($user)->get('/projects/yby-irrigation/growth?granularity=bad')->assertStatus(422);
    }

    private function userWith(array $permissions, string $roleKey = 'traffic-boss-viewer'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
