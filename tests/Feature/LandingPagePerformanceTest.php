<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LandingPagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_landing_page_performance_treats_legacy_site_as_reference_and_target_site_as_migration_destination(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        foreach ([
            ['site_key' => 'current', 'domain_normalized' => 'ybyirrigation.com', 'base_url' => 'https://ybyirrigation.com', 'is_primary' => 1],
            ['site_key' => 'legacy', 'domain_normalized' => 'yby-irrigation.com', 'base_url' => 'https://yby-irrigation.com', 'is_primary' => 0],
        ] as $site) {
            DB::table('business_project_sites')->insert([
                'business_project_id' => $project->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                ...$site,
            ]);
        }

        foreach ([
            ['/unique/', 80, 50],
            ['/shared/', 50, 20],
        ] as [$path, $sessions, $engaged]) {
            TrafficGa4DailyMetric::create([
                'business_project_id' => $project->id,
                'metric_date' => '2026-09-17',
                'scope' => 'landing_page',
                'dimension_value' => $path,
                'sessions' => $sessions,
                'total_users' => 0,
                'new_users' => 0,
                'engaged_sessions' => $engaged,
                'key_events' => 0,
                'event_count' => 0,
            ]);
        }

        $keywordId = DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $project->id,
            'keyword' => 'landing page test',
            'normalized_keyword' => 'landing page test',
            'normalized_hash' => hash('sha256', 'landing page test'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['https://ybyirrigation.com/unique/', 100, 10, 0.10, 5.0],
            ['https://ybyirrigation.com/shared/', 80, 8, 0.10, 7.0],
            ['https://yby-irrigation.com/shared/', 40, 4, 0.10, 9.0],
            ['https://tr.yby-irrigation.com/legacy-only/', 120, 12, 0.10, 6.0],
            ['https://fr.yby-irrigation.com/info/what-is-an-irrigation-system-111.html/', 50, 0, 0.00, 8.0],
            ['https://si.yby-irrigation.com/info/what-is-an-irrigation-system-222.html/', 30, 0, 0.00, 9.0],
        ] as [$url, $impressions, $clicks, $ctr, $position]) {
            DB::table('seo_gsc_daily_metrics')->insert([
                'seo_keyword_id' => $keywordId,
                'business_project_site_id' => null,
                'metric_date' => '2026-09-17',
                'page_url' => $url,
                'country' => 'us',
                'device' => 'desktop',
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'average_position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productionCase = InquiryCase::create([
            'case_number' => 'LP-PROD-1',
            'business_project_id' => $project->id,
            'title' => 'Production landing inquiry',
            'source_channel' => 'website',
            'case_stage' => 'new',
            'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => '2026-09-17 10:00:00',
            'last_inbound_at' => '2026-09-17 10:00:00',
        ]);
        $testCase = InquiryCase::create([
            'case_number' => 'LP-TEST-1',
            'business_project_id' => $project->id,
            'title' => 'Test landing inquiry',
            'source_channel' => 'website',
            'case_stage' => 'new',
            'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => '2026-09-17 11:00:00',
            'last_inbound_at' => '2026-09-17 11:00:00',
        ]);

        foreach ([
            [$productionCase->id, 'https://ybyirrigation.com/unique/?utm_source=google', 'resolved'],
            [$testCase->id, 'http://localdev.ybyirrigation.com/unique/', 'partial'],
        ] as [$caseId, $sourceUrl, $state]) {
            DB::table('case_attributions')->insert([
                'case_id' => $caseId,
                'business_project_id' => $project->id,
                'source_kind' => 'direct_or_unknown',
                'attribution_state' => $state,
                'campaign_match_state' => 'not_applicable',
                'landing_page_match_state' => 'unresolved',
                'source_url' => $sourceUrl,
                'evidence_hash' => hash('sha256', $sourceUrl),
                'evidence' => json_encode([]),
                'created_at' => '2026-09-17 12:00:00',
                'updated_at' => '2026-09-17 12:00:00',
            ]);
        }

        $this->actingAs($this->userWith('marketing.view'))
            ->get('/projects/yby-irrigation/traffic/landing-pages?date_from=2026-09-17&date_to=2026-09-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Traffic/LandingPages/Index')
                ->where('site_strategy.target_domain', 'ybyirrigation.com')
                ->where('site_strategy.legacy_domain', 'yby-irrigation.com')
                ->where('site_strategy.strategy', 'legacy_reference_to_target_migration')
                ->where('summary.target_ga4_pages', 2)
                ->where('summary.target_gsc_pages', 2)
                ->where('summary.target_gsc_clicks', 18)
                ->where('summary.legacy_reference_pages', 4)
                ->where('summary.legacy_gsc_clicks', 16)
                ->where('summary.migration_candidates', 3)
                ->where('summary.target_path_present', 1)
                ->where('summary.priority_p0', 1)
                ->where('summary.priority_p1', 2)
                ->where('summary.priority_p2', 1)
                ->where('summary.p1_content_clusters', 1)
                ->where('summary.p1_blog_clusters', 1)
                ->where('summary.p1_product_clusters', 0)
                ->where('summary.p1_category_clusters', 0)
                ->where('summary.handoff_reuse', 0)
                ->where('summary.handoff_applied_migration_rows', 0)
                ->where('summary.handoff_preexisting_reuse', 0)
                ->where('summary.handoff_review', 0)
                ->where('summary.handoff_new', 1)
                ->where('summary.mapping_total', 2)
                ->where('summary.mapping_target_present', 1)
                ->where('summary.mapping_pending_create', 1)
                ->where('summary.target_inquiry_pages', 1)
                ->where('summary.excluded_non_target_inquiry_evidence', 1)
                ->where('migration_opportunities.0.domain', 'tr.yby-irrigation.com')
                ->where('migration_opportunities.0.path', '/legacy-only/')
                ->where('migration_opportunities.0.migration_state', 'migration_candidate')
                ->where('migration_opportunities.0.priority', 'P0')
                ->where('migration_opportunities.0.target_path_present', false)
                ->where('migration_opportunities.3.domain', 'yby-irrigation.com')
                ->where('migration_opportunities.3.path', '/shared/')
                ->where('migration_opportunities.3.migration_state', 'target_path_present')
                ->where('migration_opportunities.3.priority', 'P2')
                ->where('migration_opportunities.3.target_sessions', 50)
                ->where('migration_content_plan.0.cluster_key', 'what-is-an-irrigation-system')
                ->where('migration_content_plan.0.recommended_target_type', 'Blog')
                ->where('migration_content_plan.0.seo_topic_cluster', 'Irrigation General')
                ->where('migration_content_plan.0.legacy_versions', 2)
                ->where('migration_content_plan.0.legacy_impressions', 80)
                ->where('migration_content_plan.0.execution_action', 'NEW_BLOG')
                ->where('migration_backlog_handoff.0.cluster_key', 'what-is-an-irrigation-system')
                ->where('migration_backlog_handoff.0.handoff_state', 'NEW')
                ->where('migration_backlog_handoff.0.proposed_backlog.content_type', 'blog')
                ->where('migration_backlog_handoff.0.proposed_backlog.priority', 'P1')
                ->where('migration_backlog_handoff.0.proposed_backlog.status', 'planned')
                ->where('migration_backlog_handoff.0.proposed_backlog.seo_keyword_id', null)
                ->where('migration_mapping.0.mapping_state', 'PENDING_CREATE')
                ->where('migration_mapping.0.target_url', null)
                ->where('migration_mapping.0.legacy_versions', 2)
                ->where('migration_mapping.0.legacy_impressions', 80)
                ->where('migration_mapping.1.mapping_state', 'TARGET_PATH_PRESENT')
                ->where('migration_mapping.1.target_path', '/shared/')
                ->where('migration_mapping.1.legacy_versions', 1)
                ->where('target_inquiry_pages.0.domain', 'ybyirrigation.com')
                ->where('target_inquiry_pages.0.path', '/unique/')
                ->where('target_inquiry_pages.0.inquiries', 1)
                ->where('contract.version', 'traffic-landing-page-migration-v2')
                ->where('contract.priority_policy.P0', 'legacy_clicks_gt_0_and_target_path_missing')
                ->where('contract.priority_policy.P1', 'legacy_impressions_gt_0_and_target_path_missing')
                ->where('contract.priority_policy.P2', 'target_path_present_optimize_verify')
                ->where('contract.execution_handoff', 'seo_content_backlog_after_owner_approval')
                ->where('contract.content_cluster_policy', 'strip_language_host_and_legacy_numeric_suffix')
                ->where('contract.content_type_policy', 'info_news_to_blog_product_html_to_product_directory_to_category')
                ->where('contract.topic_cluster_policy', 'deterministic_keyword_rules')
                ->where('contract.backlog_handoff_policy', 'reuse_exact_url_or_title_then_review_token_similarity_ge_0_60_else_new')
                ->where('contract.review_resolution_policy', 'explicit_owner-reviewed_intent_and_page_type_resolution_v1')
                ->where('contract.backlog_write_state', 'preview_only')
                ->where('contract.migration_mapping_policy', 'target_exact_path_or_pending_cluster_without_fabricated_url')
                ->where('contract.legacy_gsc_role', 'reference_only')
                ->where('contract.target_gsc_role', 'target_visibility')
                ->where('contract.ads_landing_page_state', 'not_available_in_current_ads_daily_model')
                ->where('contract.revenue_state', 'excluded'));
    }

    public function test_migration_review_resolution_contract_is_stable(): void
    {
        $service = app(\App\Modules\Traffic\Application\LandingPagePerformanceService::class);
        $method = new \ReflectionMethod($service, 'migrationReviewResolution');
        $method->setAccessible(true);

        $this->assertSame('KEEP_NEW', $method->invoke($service, 'benefits-of-solar-irrigation-system')['decision']);
        $this->assertSame('NEW', $method->invoke($service, 'benefits-of-solar-irrigation-system')['handoff_state']);

        $this->assertSame('REUSE', $method->invoke($service, 'drip-irrigation-accessories-and-tools')['decision']);
        $this->assertSame('REUSE', $method->invoke($service, 'drip-irrigation-accessories-and-tools')['handoff_state']);

        $this->assertSame('REUSE', $method->invoke($service, 'agriculture-drip-irrigation-pipe')['decision']);
        $this->assertSame('REUSE', $method->invoke($service, 'agriculture-drip-irrigation-pipe')['handoff_state']);

        $this->assertSame('KEEP_NEW', $method->invoke($service, 'drip-irrigation-pipe-connection-method')['decision']);
        $this->assertSame('NEW', $method->invoke($service, 'drip-irrigation-pipe-connection-method')['handoff_state']);

        $this->assertNull($method->invoke($service, 'unreviewed-topic'));
    }

    public function test_landing_page_performance_requires_marketing_view(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWith('dashboard.view', 'landing-page-no-marketing'))
            ->get('/projects/yby-irrigation/traffic/landing-pages')
            ->assertForbidden();
    }

    private function userWith(string $permissionKey, string $roleKey = 'landing-page-viewer'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        $permission = Permission::firstOrCreate(['key' => $permissionKey], ['label' => $permissionKey]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
