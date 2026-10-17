<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoGrowthEngineService;
use App\Modules\Traffic\Application\SeoGrowthExistingPublicationService;
use App\Modules\Traffic\Application\SeoPostPublishMeasurementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoGrowthExistingPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_actionable_growth_existing_page_can_close_into_publication_without_backlog(): void
    {
        Carbon::setTestNow('2026-09-22 11:30:00');
        $project = $this->projectWithSites();
        $keywordId = $this->keyword($project->id, 'mulch film', 'OPTIMIZE_P2', 23.85);
        $legacy = 'https://www.yby-irrigation.com/pipes-pipe-fittings/agricultural-mulch-film.html';
        $published = 'https://ybyirrigation.com/pipes-pipe-fittings/agricultural-mulch-film.html';
        $this->gsc($keywordId, $legacy, 46, 0, 17.76087174);

        $growthBefore = app(SeoGrowthEngineService::class)->build('yby-irrigation');
        $candidate = collect($growthBefore['queue'])->firstWhere('keyword', 'mulch film');
        $this->assertNotNull($candidate);
        $this->assertSame('existing_page', $candidate['kind']);
        $this->assertSame('P1', $candidate['priority']);
        $this->assertSame('push_page1', $candidate['decision']);

        $service = app(SeoGrowthExistingPublicationService::class);
        $dry = $service->publish(
            'yby-irrigation',
            $legacy,
            $published,
            796,
            '2026-09-22',
            '0343232c33ae016783db4e08384586b503129f04',
            'dry_run'
        );

        $this->assertSame([], $dry['blocked']);
        $this->assertSame(1, $dry['seen']);
        $this->assertSame(0, $dry['applied']);
        $this->assertDatabaseCount('seo_optimization_work_orders', 0);
        $this->assertNull($dry['payload']['seo_content_backlog_id']);
        $this->assertSame(46, $dry['payload']['gsc_impressions']);
        $this->assertSame('2026-10-20', $dry['payload']['retest_after']);
        $apply = $service->publish(
            'yby-irrigation',
            $legacy,
            $published,
            796,
            '2026-09-22',
            '0343232c33ae016783db4e08384586b503129f04',
            'apply'
        );

        $this->assertSame(1, $apply['applied']);
        $this->assertNotNull($apply['work_order_id']);

        $order = DB::table('seo_optimization_work_orders')->where('id', $apply['work_order_id'])->first();
        $this->assertNull($order->seo_content_backlog_id);
        $this->assertSame($keywordId, (int) $order->primary_seo_keyword_id);
        $this->assertSame($legacy, $order->target_url);
        $this->assertSame(46, (int) $order->gsc_impressions);
        $this->assertSame(0, (int) $order->gsc_clicks);
        $this->assertEqualsWithDelta(17.76087174, (float) $order->gsc_average_position, 0.0001);
        $this->assertSame('published', $order->workflow_status);
        $this->assertSame('2026-09-14', $order->baseline_from);
        $this->assertSame('2026-09-14', $order->baseline_to);
        $this->assertSame('2026-10-20', $order->retest_after);

        $meta = json_decode($order->recommendations, true);
        $this->assertSame('SEO7-GROWTH-V1', $meta['growth_existing']['source']);
        $this->assertTrue($meta['growth_existing']['no_backlog_required']);
        $this->assertSame('pass', $meta['production']['owner_uat']);
        $this->assertSame(796, $meta['production']['post_id']);
        $this->assertSame($published, $meta['production']['canonical_url']);
        $measurements = app(SeoPostPublishMeasurementService::class);
        $register = $measurements->register('yby-irrigation', 'apply', (int) $order->id);
        $snapshot = $measurements->snapshot('yby-irrigation', 'T0', 'apply', (int) $order->id);

        $this->assertSame(1, $register['seen']);
        $this->assertSame(1, $register['applied']);
        $this->assertSame(2, $snapshot['seen']);
        $this->assertSame(2, $snapshot['applied']);

        $publication = DB::table('seo_optimization_publications')->first();
        $this->assertSame($legacy, $publication->baseline_url);
        $this->assertSame($published, $publication->published_url);
        $this->assertSame(796, (int) $publication->production_post_id);
        $this->assertSame('monitoring', $publication->status);

        $roles = DB::table('seo_optimization_measurements')
            ->pluck('url_role')->sort()->values()->all();
        $this->assertSame(['legacy', 'published'], $roles);

        $growthAfter = app(SeoGrowthEngineService::class)->build('yby-irrigation');
        $this->assertNotContains(
            'mulch film',
            collect($growthAfter['queue'])->pluck('keyword')->all()
        );
        $this->assertSame(1, $growthAfter['summary']['monitoring']);
    }

    public function test_non_actionable_url_is_blocked(): void
    {
        $this->projectWithSites();
        $result = app(SeoGrowthExistingPublicationService::class)->publish(
            'yby-irrigation',
            'https://www.yby-irrigation.com/not-a-growth-candidate.html',
            'https://ybyirrigation.com/not-a-growth-candidate.html',
            999,
            '2026-09-22',
            '0343232c33ae016783db4e08384586b503129f04',
            'apply'
        );

        $this->assertSame(['growth_candidate_not_actionable'], $result['blocked']);
        $this->assertDatabaseCount('seo_optimization_work_orders', 0);
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

    private function keyword(int $projectId, string $keyword, string $action, float $score): int
    {
        return DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $projectId,
            'keyword' => $keyword,
            'normalized_keyword' => strtolower($keyword),
            'normalized_hash' => hash('sha256', strtolower($keyword)),
            'topic_cluster' => 'Other',
            'intent' => 'commercial',
            'suggested_target_type' => 'Review',
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
