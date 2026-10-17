<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoGrowthEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoGrowthEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_growth_queue_scopes_exact_english_hosts_and_uses_consolidated_content_backlog(): void
    {
        Carbon::setTestNow('2026-09-19 10:00:00');
        $project = $this->projectWithSites();

        $english = $this->keyword($project->id, 'drip irrigation filter', 'PAGE1_P0', 82.5, 'Product/Category Page');
        $international = $this->keyword($project->id, 'irrigation goutte a goutte', 'PAGE1_P0', 95.0, 'Review');
        $content = $this->keyword($project->id, 'items needed for drip irrigation', 'NEW_CONTENT_P0', 31.0, 'Blog');

        $this->gsc($english, 'https://www.yby-irrigation.com/drip-irrigation-system/filter.html', 100, 2, 15.0);
        $this->gsc($international, 'https://fr.yby-irrigation.com/drip-irrigation-system/filter.html', 500, 2, 7.0);

        DB::table('seo_content_backlog')->insert([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $content,
            'topic_cluster' => 'Irrigation General',
            'content_type' => 'blog',
            'priority' => 'P0',
            'status' => 'planned',
            'title' => 'items needed for drip irrigation',
            'target_url' => null,
            'rationale' => 'Consolidated NEW_CONTENT_P0 task.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_optimization_work_orders')->insert([
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => null,
            'primary_seo_keyword_id' => $international,
            'target_url_hash' => hash('sha256', 'https://fr.yby-irrigation.com/legacy.html'),
            'target_url' => 'https://fr.yby-irrigation.com/legacy.html',
            'supporting_keywords' => json_encode([]),
            'gsc_impressions' => 500,
            'gsc_clicks' => 2,
            'gsc_ctr' => 0.004,
            'gsc_average_position' => 7,
            'priority_score' => 95,
            'recommendations' => json_encode([]),
            'workflow_status' => 'planned',
            'baseline_from' => '2026-09-01',
            'baseline_to' => '2026-09-14',
            'retest_after' => '2026-10-12',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SeoGrowthEngineService::class)->build('yby-irrigation');

        $this->assertSame('SEO7-GROWTH-V1', $result['version']);
        $this->assertContains('www.yby-irrigation.com', $result['scope']['hosts']);
        $this->assertSame(2, $result['summary']['actionable']);
        $this->assertSame(1, $result['summary']['existing_page']);
        $this->assertSame(1, $result['summary']['new_content']);
        $this->assertSame(1, $result['summary']['international_planned']);
        $this->assertSame(1, $result['data_health']['international_gsc_rows_excluded']);
        $this->assertTrue($result['data_health']['consolidated_new_content_truth']);

        $keywords = collect($result['queue'])->pluck('keyword')->all();
        $this->assertContains('drip irrigation filter', $keywords);
        $this->assertContains('items needed for drip irrigation', $keywords);
        $this->assertNotContains('irrigation goutte a goutte', $keywords);
    }

    public function test_new_content_is_filtered_when_live_gsc_maps_to_an_existing_publication(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $project = $this->projectWithSites();

        $publishedKeyword = $this->keyword(
            $project->id,
            'solar irrigation system',
            'PAGE1_P0',
            43.35,
            'Product/Category Page'
        );
        $newContentKeyword = $this->keyword(
            $project->id,
            'solar water pump for irrigation',
            'NEW_CONTENT_P0',
            28.12,
            'Product/Category Page'
        );

        $legacyUrl = 'https://www.yby-irrigation.com/farm-irrigation-system/solar-irrigation-system.html';
        $publishedUrl = 'https://ybyirrigation.com/farm-irrigation-system/solar-irrigation-system.html';

        $this->gsc($newContentKeyword, $legacyUrl, 18, 0, 41.22);

        DB::table('seo_content_backlog')->insert([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $newContentKeyword,
            'topic_cluster' => 'Solar Irrigation / Pump',
            'content_type' => 'commercial_page',
            'priority' => 'P0',
            'status' => 'planned',
            'title' => 'solar water pump for irrigation',
            'target_url' => null,
            'rationale' => 'NEW_CONTENT_P0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrderId = DB::table('seo_optimization_work_orders')->insertGetId([
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => null,
            'primary_seo_keyword_id' => $publishedKeyword,
            'target_url_hash' => hash('sha256', $legacyUrl),
            'target_url' => $legacyUrl,
            'supporting_keywords' => json_encode([]),
            'gsc_impressions' => 70,
            'gsc_clicks' => 0,
            'gsc_ctr' => 0,
            'gsc_average_position' => 25.94,
            'priority_score' => 70,
            'recommendations' => json_encode(['production' => [
                'post_id' => 776,
                'canonical_url' => $publishedUrl,
                'publish_date' => '2026-09-18',
                'retest_after' => '2026-10-16',
            ]]),
            'workflow_status' => 'published',
            'baseline_from' => '2026-09-01',
            'baseline_to' => '2026-09-14',
            'retest_after' => '2026-10-16',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_optimization_publications')->insert([
            'seo_optimization_work_order_id' => $workOrderId,
            'baseline_url' => $legacyUrl,
            'published_url' => $publishedUrl,
            'production_post_id' => 776,
            'publish_date' => '2026-09-18',
            'retest_after' => '2026-10-16',
            'baseline_impressions' => 70,
            'baseline_clicks' => 0,
            'baseline_ctr' => 0,
            'baseline_average_position' => 25.94,
            'status' => 'monitoring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SeoGrowthEngineService::class)->build('yby-irrigation');

        $this->assertSame(0, $result['summary']['new_content']);
        $this->assertSame(1, $result['summary']['new_content_publication_mapped_filtered']);
        $this->assertNotContains(
            'solar water pump for irrigation',
            collect($result['queue'])->pluck('keyword')->all()
        );
    }

    public function test_existing_news_page_is_filtered_when_owned_by_planned_migration_backlog(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $project = $this->projectWithSites();

        $keyword = $this->keyword(
            $project->id,
            'solar irrigation system',
            'PAGE1_P0',
            43.35,
            'Product/Category Page'
        );
        $legacyNews = 'https://www.yby-irrigation.com/news/benefits-of-solar-irrigation-system-58355404.html';
        $this->gsc($keyword, $legacyNews, 32, 0, 20.87);

        DB::table('seo_content_backlog')->insert([
            'business_project_id' => $project->id,
            'seo_keyword_id' => null,
            'topic_cluster' => 'Solar Irrigation / Pump',
            'content_type' => 'blog',
            'priority' => 'P1',
            'status' => 'planned',
            'title' => 'Benefits Of Solar Irrigation System',
            'target_url' => null,
            'rationale' => 'Legacy migration cluster; versions=4 impressions=31 avg_position=23.58; source=Traffic OS Migration Content Plan.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SeoGrowthEngineService::class)->build('yby-irrigation');

        $this->assertSame(0, $result['summary']['actionable']);
        $this->assertSame(0, $result['summary']['existing_page']);
        $this->assertSame(1, $result['summary']['existing_page_migration_filtered']);
        $this->assertSame(1, $result['summary']['migration_content_planned']);
        $this->assertNotContains(
            'solar irrigation system',
            collect($result['queue'])->pluck('keyword')->all()
        );
    }

    public function test_published_page_is_filtered_and_t28_daily_normalized_signal_can_be_winner(): void
    {
        Carbon::setTestNow('2026-09-19 10:00:00');
        $project = $this->projectWithSites();
        $keyword = $this->keyword($project->id, 'drip irrigation system', 'PAGE1_P0', 88.0, 'Product/Category Page');
        $legacyUrl = 'https://www.yby-irrigation.com/drip-irrigation-system/';
        $publishedUrl = 'https://ybyirrigation.com/drip-irrigation-system/';

        $this->gsc($keyword, $legacyUrl, 100, 1, 20.0);

        $workOrderId = DB::table('seo_optimization_work_orders')->insertGetId([
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => null,
            'primary_seo_keyword_id' => $keyword,
            'target_url_hash' => hash('sha256', $legacyUrl),
            'target_url' => $legacyUrl,
            'supporting_keywords' => json_encode([]),
            'gsc_impressions' => 100,
            'gsc_clicks' => 1,
            'gsc_ctr' => 0.01,
            'gsc_average_position' => 20,
            'priority_score' => 88,
            'recommendations' => json_encode(['production' => [
                'owner_uat' => 'pass',
                'post_id' => 793,
                'url' => $publishedUrl,
                'canonical_url' => $publishedUrl,
                'publish_date' => '2026-08-18',
                'retest_after' => '2026-09-15',
            ]]),
            'workflow_status' => 'published',
            'baseline_from' => '2026-08-01',
            'baseline_to' => '2026-08-14',
            'retest_after' => '2026-09-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicationId = DB::table('seo_optimization_publications')->insertGetId([
            'seo_optimization_work_order_id' => $workOrderId,
            'baseline_url' => $legacyUrl,
            'published_url' => $publishedUrl,
            'production_post_id' => 793,
            'publish_date' => '2026-08-18',
            'retest_after' => '2026-09-15',
            'baseline_impressions' => 100,
            'baseline_clicks' => 1,
            'baseline_ctr' => 0.01,
            'baseline_average_position' => 20,
            'status' => 'monitoring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_optimization_measurements')->insert([
            'seo_optimization_publication_id' => $publicationId,
            'snapshot_label' => 'T28',
            'url_role' => 'published',
            'page_url' => $publishedUrl,
            'metric_from' => '2026-08-18',
            'metric_to' => '2026-09-14',
            'impressions' => 280,
            'clicks' => 10,
            'ctr' => 10 / 280,
            'average_position' => 10,
            'distinct_queries' => 3,
            'competing_pages' => 0,
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SeoGrowthEngineService::class)->build('yby-irrigation');

        $this->assertSame(0, $result['summary']['actionable']);
        $this->assertSame(1, $result['summary']['published_pages_filtered']);
        $this->assertSame(1, $result['summary']['winner']);
        $this->assertSame('winner', $result['monitoring'][0]['state']);
        $this->assertSame('pass', $result['monitoring'][0]['owner_uat']);
        $this->assertSame(10.0, $result['monitoring'][0]['signal']['position_delta']);
        $this->assertGreaterThan(0.3, $result['monitoring'][0]['signal']['impressions_daily_change']);
    }

    public function test_existing_page_is_filtered_when_primary_keyword_is_already_published_on_another_canonical_url(): void
    {
        Carbon::setTestNow('2026-09-22 10:00:00');
        $project = $this->projectWithSites();
        $keyword = $this->keyword($project->id, 'micro sprinkler heads', 'PAGE1_P0', 42.52, 'Product/Category Page');
        $publishedLegacyUrl = 'https://www.yby-irrigation.com/garden-watering/drip-sprinkler-system.html';
        $duplicateLegacyUrl = 'https://www.yby-irrigation.com/spray-irrigation/atomizing-micro-sprinklers-on-spike.html';
        $publishedUrl = 'https://ybyirrigation.com/garden-watering/drip-sprinkler-system.html';

        $this->gsc($keyword, $duplicateLegacyUrl, 39, 0, 20.97);

        $workOrderId = DB::table('seo_optimization_work_orders')->insertGetId([
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => null,
            'primary_seo_keyword_id' => $keyword,
            'target_url_hash' => hash('sha256', $publishedLegacyUrl),
            'target_url' => $publishedLegacyUrl,
            'supporting_keywords' => json_encode([]),
            'gsc_impressions' => 21,
            'gsc_clicks' => 0,
            'gsc_ctr' => 0,
            'gsc_average_position' => 19.67,
            'priority_score' => 42.52,
            'recommendations' => json_encode(['production' => ['post_id' => 783]]),
            'workflow_status' => 'published',
            'baseline_from' => '2026-09-01',
            'baseline_to' => '2026-09-14',
            'retest_after' => '2026-10-17',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_optimization_publications')->insert([
            'seo_optimization_work_order_id' => $workOrderId,
            'baseline_url' => $publishedLegacyUrl,
            'published_url' => $publishedUrl,
            'production_post_id' => 783,
            'publish_date' => '2026-09-19',
            'retest_after' => '2026-10-17',
            'baseline_impressions' => 21,
            'baseline_clicks' => 0,
            'baseline_ctr' => 0,
            'baseline_average_position' => 19.67,
            'status' => 'monitoring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SeoGrowthEngineService::class)->build('yby-irrigation');

        $this->assertSame(0, $result['summary']['actionable']);
        $this->assertSame(0, $result['summary']['published_pages_filtered']);
        $this->assertSame(1, $result['summary']['published_keyword_filtered']);
        $this->assertNotContains('micro sprinkler heads', collect($result['queue'])->pluck('keyword')->all());
    }

    private function projectWithSites(): BusinessProject
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        foreach ([
            ['site_key' => 'new', 'domain_normalized' => 'ybyirrigation.com', 'base_url' => 'https://ybyirrigation.com', 'is_primary' => 1],
            ['site_key' => 'legacy', 'domain_normalized' => 'yby-irrigation.com', 'base_url' => 'https://yby-irrigation.com', 'is_primary' => 0],
        ] as $site) {
            DB::table('business_project_sites')->insert($site + [
                'business_project_id' => $project->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $project;
    }

    private function keyword(int $projectId, string $keyword, string $action, float $score, string $target): int
    {
        return DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $projectId,
            'keyword' => $keyword,
            'normalized_keyword' => strtolower($keyword),
            'normalized_hash' => hash('sha256', strtolower($keyword)),
            'topic_cluster' => 'Irrigation',
            'intent' => 'commercial',
            'suggested_target_type' => $target,
            'suggested_action' => $action,
            'opportunity_score' => $score,
            'is_competitor_term' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function gsc(int $keywordId, string $url, int $impressions, int $clicks, float $position): void
    {
        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id' => $keywordId,
            'business_project_site_id' => null,
            'metric_date' => '2026-09-14',
            'page_url' => $url,
            'country' => 'usa',
            'device' => 'DESKTOP',
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
            'average_position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
