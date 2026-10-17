<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoNewContentPublicationService;
use App\Modules\Traffic\Application\SeoPostPublishMeasurementService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoNewContentPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_content_backlog_becomes_published_work_order_and_t0_only_uses_published_role(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active',
        ]);
        DB::table('business_project_sites')->insert([
            'business_project_id' => $project->id,
            'site_key' => 'new',
            'domain_normalized' => 'ybyirrigation.com',
            'base_url' => 'https://ybyirrigation.com',
            'is_primary' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primary = SeoKeyword::create([
            'business_project_id' => $project->id,
            'keyword' => 'amiran drip irrigation kit price',
            'normalized_keyword' => 'amiran drip irrigation kit price',
            'normalized_hash' => hash('sha256', 'amiran drip irrigation kit price'),
            'topic_cluster' => 'Drip Irrigation Kit',
            'suggested_target_type' => 'Comparison Blog',
            'suggested_action' => 'NEW_CONTENT_P0',
            'opportunity_score' => 28.12,
            'status' => 'active',
        ]);
        $support = SeoKeyword::create([
            'business_project_id' => $project->id,
            'keyword' => 'elgon kenya drip irrigation kit',
            'normalized_keyword' => 'elgon kenya drip irrigation kit',
            'normalized_hash' => hash('sha256', 'elgon kenya drip irrigation kit'),
            'topic_cluster' => 'Drip Irrigation Kit',
            'suggested_target_type' => 'Comparison Blog',
            'suggested_action' => 'NEW_CONTENT_P0',
            'opportunity_score' => 12,
            'status' => 'active',
        ]);

        $backlogId = DB::table('seo_content_backlog')->insertGetId([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $primary->id,
            'topic_cluster' => 'Drip Irrigation Kit',
            'content_type' => 'comparison_blog',
            'priority' => 'P0',
            'status' => 'planned',
            'title' => 'Drip irrigation kit brands and price comparison',
            'target_url' => null,
            'rationale' => 'Competitor-intent cluster',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('seo_content_backlog')->insert([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $support->id,
            'topic_cluster' => 'Drip Irrigation Kit',
            'content_type' => 'cluster_member',
            'priority' => 'P0',
            'status' => 'retired',
            'title' => 'elgon kenya drip irrigation kit',
            'target_url' => null,
            'rationale' => 'Merged into competitor comparison backlog #'.$backlogId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = 'https://ybyirrigation.com/drip-irrigation-kit-price-comparison-kenya/';
        $service = app(SeoNewContentPublicationService::class);

        $dry = $service->publish(
            'yby-irrigation', $backlogId, $url, 795, '2026-09-20',
            'e91a32e0d287efa463e7faa48d735637a9ccb4ff', 'dry_run'
        );
        $this->assertSame(1, $dry['seen']);
        $this->assertSame(0, $dry['applied']);
        $this->assertDatabaseCount('seo_optimization_work_orders', 0);
        $this->assertDatabaseHas('seo_content_backlog', ['id' => $backlogId, 'status' => 'planned']);

        $apply = $service->publish(
            'yby-irrigation', $backlogId, $url, 795, '2026-09-20',
            'e91a32e0d287efa463e7faa48d735637a9ccb4ff', 'apply'
        );
        $this->assertSame(1, $apply['applied']);
        $this->assertNotNull($apply['work_order_id']);
        $this->assertDatabaseHas('seo_content_backlog', ['id' => $backlogId, 'status' => 'published']);

        $workOrder = DB::table('seo_optimization_work_orders')->where('id', $apply['work_order_id'])->first();
        $this->assertSame($backlogId, (int) $workOrder->seo_content_backlog_id);
        $this->assertSame($primary->id, (int) $workOrder->primary_seo_keyword_id);
        $this->assertSame($url, $workOrder->target_url);
        $this->assertSame(0, (int) $workOrder->gsc_impressions);
        $this->assertSame(0, (int) $workOrder->gsc_clicks);
        $this->assertNull($workOrder->gsc_average_position);
        $this->assertSame('published', $workOrder->workflow_status);
        $this->assertSame('2026-10-18', $workOrder->retest_after);
        $this->assertSame(['elgon kenya drip irrigation kit'], json_decode($workOrder->supporting_keywords, true));

        $meta = json_decode($workOrder->recommendations, true);
        $this->assertTrue($meta['new_content']['no_legacy_baseline']);
        $this->assertSame(795, $meta['production']['post_id']);
        $this->assertSame($url, $meta['production']['canonical_url']);
        $this->assertSame('pass', $meta['production']['owner_uat']);

        $measurements = app(SeoPostPublishMeasurementService::class);
        $register = $measurements->register('yby-irrigation', 'apply', (int) $workOrder->id);
        $snapshot = $measurements->snapshot('yby-irrigation', 'T0', 'apply', (int) $workOrder->id);

        $this->assertSame(1, $register['seen']);
        $this->assertSame(1, $register['applied']);
        $this->assertSame(1, $snapshot['seen']);
        $this->assertSame(1, $snapshot['applied']);
        $this->assertDatabaseCount('seo_optimization_publications', 1);
        $this->assertDatabaseCount('seo_optimization_measurements', 1);

        $publication = DB::table('seo_optimization_publications')->first();
        $this->assertSame($url, $publication->baseline_url);
        $this->assertSame($url, $publication->published_url);
        $this->assertSame(0, (int) $publication->baseline_impressions);

        $measurement = DB::table('seo_optimization_measurements')->first();
        $this->assertSame('T0', $measurement->snapshot_label);
        $this->assertSame('published', $measurement->url_role);
        $this->assertSame($url, $measurement->page_url);
    }
}
