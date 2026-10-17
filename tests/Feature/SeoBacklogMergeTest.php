<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoBacklogMergeService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoBacklogMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_planned_new_content_into_existing_canonical_without_mutating_publication(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active',
        ]);
        $targetKeyword = SeoKeyword::create([
            'business_project_id' => $project->id,
            'keyword' => 'components of drip irrigation system',
            'normalized_keyword' => 'components of drip irrigation system',
            'normalized_hash' => hash('sha256', 'components of drip irrigation system'),
            'status' => 'active',
        ]);
        $sourceKeyword = SeoKeyword::create([
            'business_project_id' => $project->id,
            'keyword' => 'items needed for drip irrigation',
            'normalized_keyword' => 'items needed for drip irrigation',
            'normalized_hash' => hash('sha256', 'items needed for drip irrigation'),
            'suggested_action' => 'NEW_CONTENT_P0',
            'suggested_target_type' => 'Blog',
            'status' => 'active',
        ]);

        $targetUrl = 'https://www.yby-irrigation.com/drip-irrigation-system/components-of-drip-irrigation-system.html';
        $targetBacklog = DB::table('seo_content_backlog')->insertGetId([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $targetKeyword->id,
            'content_type' => 'optimize_existing',
            'priority' => 'P0',
            'status' => 'optimize',
            'title' => 'components of drip irrigation system',
            'target_url' => $targetUrl,
            'rationale' => 'existing canonical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceBacklog = DB::table('seo_content_backlog')->insertGetId([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $sourceKeyword->id,
            'content_type' => 'blog',
            'priority' => 'P0',
            'status' => 'planned',
            'title' => 'items needed for drip irrigation',
            'target_url' => null,
            'rationale' => 'NEW_CONTENT_P0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrderId = DB::table('seo_optimization_work_orders')->insertGetId([
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => $targetBacklog,
            'primary_seo_keyword_id' => $targetKeyword->id,
            'target_url_hash' => hash('sha256', $targetUrl),
            'target_url' => $targetUrl,
            'supporting_keywords' => json_encode([]),
            'gsc_impressions' => 32, 'gsc_clicks' => 0, 'gsc_ctr' => 0,
            'gsc_average_position' => 12.3125, 'priority_score' => 32,
            'recommendations' => json_encode([]),
            'workflow_status' => 'published',
            'baseline_from' => '2026-09-01',
            'baseline_to' => '2026-09-14',
            'retest_after' => '2026-10-16',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $publicationId = DB::table('seo_optimization_publications')->insertGetId([
            'seo_optimization_work_order_id' => $workOrderId,
            'baseline_url' => $targetUrl,
            'published_url' => 'https://ybyirrigation.com/drip-irrigation-system/components-of-drip-irrigation-system.html',
            'production_post_id' => 778,
            'publish_date' => '2026-09-18',
            'retest_after' => '2026-10-16',
            'baseline_impressions' => 32,
            'baseline_clicks' => 0,
            'baseline_ctr' => 0,
            'baseline_average_position' => 12.3125,
            'status' => 'monitoring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(SeoBacklogMergeService::class);
        $dry = $service->merge('yby-irrigation', $sourceBacklog, $targetBacklog, 'dry_run');

        $this->assertSame(1, $dry['seen']);
        $this->assertSame(0, $dry['applied']);
        $this->assertSame($workOrderId, $dry['target']['work_order_id']);
        $this->assertSame($publicationId, $dry['target']['publication_id']);
        $this->assertSame('2026-10-16', $dry['target']['retest_after']);
        $this->assertDatabaseHas('seo_content_backlog', [
            'id' => $sourceBacklog, 'content_type' => 'blog', 'status' => 'planned', 'target_url' => null,
        ]);

        $apply = $service->merge('yby-irrigation', $sourceBacklog, $targetBacklog, 'apply');
        $this->assertSame(1, $apply['applied']);
        $this->assertDatabaseHas('seo_content_backlog', [
            'id' => $sourceBacklog,
            'content_type' => 'cluster_member',
            'status' => 'retired',
            'target_url' => $targetUrl,
        ]);
        $this->assertDatabaseHas('seo_content_backlog', [
            'id' => $targetBacklog,
            'content_type' => 'optimize_existing',
            'status' => 'optimize',
            'target_url' => $targetUrl,
        ]);

        $publication = DB::table('seo_optimization_publications')->where('id', $publicationId)->first();
        $this->assertSame('monitoring', $publication->status);
        $this->assertSame('2026-10-16', $publication->retest_after);
        $this->assertSame(32, (int) $publication->baseline_impressions);
    }
}
