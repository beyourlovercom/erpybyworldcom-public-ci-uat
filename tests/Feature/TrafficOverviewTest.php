<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TrafficOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_project_traffic_route_renders_multisource_overview_with_real_connected_states(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $account = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'google_ads',
            'external_account_id' => 'ads-1',
            'name' => 'YBY Ads',
            'currency_code' => 'USD',
            'status' => 'active',
        ]);

        $campaign = TrafficCampaign::create([
            'traffic_source_account_id' => $account->id,
            'external_campaign_id' => 'campaign-1',
            'name' => 'Irrigation Search',
            'status' => 'enabled',
        ]);

        TrafficDailyMetric::create([
            'traffic_campaign_id' => $campaign->id,
            'metric_date' => '2026-09-17',
            'impressions' => 1000,
            'clicks' => 125,
            'cost_micros' => 250000000,
            'provider_conversions' => 3,
        ]);

        $keywordId = DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $project->id,
            'keyword' => 'drip irrigation supplier',
            'normalized_keyword' => 'drip irrigation supplier',
            'normalized_hash' => hash('sha256', 'drip irrigation supplier'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id' => $keywordId,
            'business_project_site_id' => null,
            'metric_date' => '2026-09-17',
            'page_url' => 'https://ybyirrigation.com/',
            'country' => 'us',
            'device' => 'desktop',
            'impressions' => 300,
            'clicks' => 15,
            'ctr' => 0.05,
            'average_position' => 8.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InquiryCase::create([
            'case_number' => 'TRAFFIC-V2-1',
            'business_project_id' => $project->id,
            'title' => 'Traffic V2 inquiry',
            'source_channel' => 'website',
            'case_stage' => 'new',
            'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => '2026-09-17 10:00:00',
            'last_inbound_at' => '2026-09-17 10:00:00',
        ]);

        $this->actingAs($this->userWith('marketing.view'))
            ->get('/projects/yby-irrigation/traffic?date_from=2026-09-17&date_to=2026-09-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Traffic/Overview')
                ->where('project.key', 'yby-irrigation')
                ->where('summary.platform_impressions', 1300)
                ->where('summary.platform_clicks', 140)
                ->where('summary.website_sessions', null)
                ->where('summary.engaged_sessions', null)
                ->where('summary.inquiries', 1)
                ->where('channels.0.key', 'website')
                ->where('channels.0.connected', false)
                ->where('channels.1.key', 'google_ads')
                ->where('channels.1.connected', true)
                ->where('channels.1.impressions', 1000)
                ->where('channels.1.clicks', 125)
                ->where('channels.2.key', 'seo')
                ->where('channels.2.connected', true)
                ->where('channels.2.impressions', 300)
                ->where('channels.2.clicks', 15)
                ->where('channels.2.status', '已接入')
                ->where('channels.3.key', 'facebook')
                ->where('channels.3.connected', false)
                ->where('channels.3.status', '接入能力已就绪，等待账号授权')
                ->where('channels.4.key', 'youtube')
                ->where('channels.4.status', '已暂缓，周一继续授权')
                ->where('channels.5.key', 'tiktok')
                ->where('channels.5.status', '已暂缓，周一继续授权')
                ->has('trend', 1)
                ->where('trend.0.clicks', 140)
                ->where('trend.0.impressions', 1300)
                ->where('trend.0.inquiries', 1)
                ->has('top_items', 2)
                ->where('top_items.0.name', 'Irrigation Search')
                ->where('top_items.0.channel', 'Google Ads')
                ->where('top_items.1.name', 'drip irrigation supplier')
                ->where('top_items.1.channel', 'SEO / GSC')
                ->where('top_items.1.impressions', 300)
                ->where('top_items.1.clicks', 15)
                ->where('contract.version', 'traffic-os-v2-cross-channel-overview-v1')
                ->where('contract.platform_impressions_are_cross_platform', false)
                ->where('contract.platform_metric_semantics', 'source_native')
                ->where('contract.summary_scope', 'date_scoped_sources_only')
                ->where('contract.summary_sources.0', 'google_ads')
                ->where('contract.summary_sources.1', 'gsc')
                ->where('contract.deferred_sources.0', 'youtube')
                ->where('contract.deferred_sources.1', 'tiktok')
                ->where('contract.gsc_metrics_state', 'daily_available')
                ->where('contract.revenue_state', 'excluded'));
    }

    public function test_gsc_legacy_evidence_never_leaks_into_date_range_totals(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $keywordId = DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $project->id,
            'keyword' => 'legacy keyword',
            'normalized_keyword' => 'legacy keyword',
            'normalized_hash' => hash('sha256', 'legacy keyword'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_keyword_evidence')->insert([
            'seo_keyword_id' => $keywordId,
            'source_type' => 'google_search_console',
            'source_key' => 'legacy-gsc',
            'impressions' => 999999,
            'clicks' => 9999,
            'conversions' => 0,
            'cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->userWith('marketing.view', 'traffic-gsc-legacy-viewer'))
            ->get('/projects/yby-irrigation/traffic?date_from=2026-09-17&date_to=2026-09-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.platform_impressions', 0)
                ->where('summary.platform_clicks', 0)
                ->where('channels.2.key', 'seo')
                ->where('channels.2.connected', true)
                ->where('channels.2.impressions', null)
                ->where('channels.2.clicks', null)
                ->where('channels.2.status', '已接入（仅历史累计，无日期）')
                ->where('contract.gsc_metrics_state', 'legacy_cumulative_only'));
    }

    public function test_project_traffic_route_requires_marketing_view(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWith('dashboard.view', 'traffic-overview-no-marketing'))
            ->get('/projects/yby-irrigation/traffic')
            ->assertForbidden();
    }

    private function userWith(string $permissionKey, string $roleKey = 'traffic-overview-viewer'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        $permission = Permission::firstOrCreate(['key' => $permissionKey], ['label' => $permissionKey]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
