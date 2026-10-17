<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoContentBacklogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoContentBacklogTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_idempotent_optimize_and_new_content_backlog(): void
    {
        $project = BusinessProject::create(['project_key'=>'yby-irrigation','name'=>'YBY Irrigation','status'=>'active']);
        $optimize = DB::table('seo_keywords')->insertGetId([
            'business_project_id'=>$project->id,'keyword'=>'drip tape','normalized_keyword'=>'drip tape',
            'normalized_hash'=>hash('sha256','drip tape'),'suggested_action'=>'PAGE1_P0','suggested_target_type'=>'Product/Category Page',
            'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $blog = DB::table('seo_keywords')->insertGetId([
            'business_project_id'=>$project->id,'keyword'=>'irrigation guide','normalized_keyword'=>'irrigation guide',
            'normalized_hash'=>hash('sha256','irrigation guide'),'suggested_action'=>'NEW_CONTENT_P0','suggested_target_type'=>'Blog',
            'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id'=>$optimize,'metric_date'=>'2026-09-16','page_url'=>'https://yby-irrigation.com/drip-tape',
            'impressions'=>100,'clicks'=>2,'ctr'=>0.02,'average_position'=>14.5,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $service = app(SeoContentBacklogService::class);
        $dry = $service->generate('yby-irrigation','dry_run');
        $this->assertSame(2, $dry['seen']);
        $this->assertSame(0, $dry['applied']);

        $first = $service->generate('yby-irrigation','apply');
        $second = $service->generate('yby-irrigation','apply');
        $this->assertSame(2, $first['applied']);
        $this->assertSame(2, $second['applied']);
        $this->assertDatabaseCount('seo_content_backlog', 2);
        $this->assertDatabaseHas('seo_content_backlog', [
            'seo_keyword_id'=>$optimize,'content_type'=>'optimize_existing','status'=>'optimize',
            'target_url'=>'https://yby-irrigation.com/drip-tape',
        ]);
        $this->assertDatabaseHas('seo_content_backlog', [
            'seo_keyword_id'=>$blog,'content_type'=>'blog','status'=>'planned','target_url'=>null,
        ]);
    }
}
