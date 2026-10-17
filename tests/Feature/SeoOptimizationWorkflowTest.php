<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoOptimizationWorkflowService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoOptimizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_one_page_work_order_with_primary_and_supporting_keywords(): void
    {
        $project=BusinessProject::create(['project_key'=>'yby-irrigation','name'=>'YBY Irrigation','status'=>'active']);
        $primary=$this->keyword($project->id,'drip tape');
        $support=$this->keyword($project->id,'irrigation tape');
        $url='https://example.com/drip-tape';
        $backlogId=DB::table('seo_content_backlog')->insertGetId([
            'business_project_id'=>$project->id,'seo_keyword_id'=>$primary->id,'content_type'=>'optimize_existing',
            'priority'=>'P0','status'=>'optimize','title'=>'drip tape','target_url'=>$url,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('seo_content_backlog')->insert([
            'business_project_id'=>$project->id,'seo_keyword_id'=>$support->id,'content_type'=>'cluster_member',
            'priority'=>'P0','status'=>'retired','title'=>'irrigation tape','target_url'=>$url,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->metric($primary->id,$url,100,2,8.0,'2026-09-10');
        $this->metric($support->id,$url,40,0,12.0,'2026-09-11');

        $service=app(SeoOptimizationWorkflowService::class);
        $first=$service->build('yby-irrigation','apply');
        $second=$service->build('yby-irrigation','apply');

        $this->assertSame(1,$first['applied']);
        $this->assertSame(1,$second['applied']);
        $this->assertDatabaseCount('seo_optimization_work_orders',1);
        $row=DB::table('seo_optimization_work_orders')->first();
        $this->assertSame($backlogId,(int)$row->seo_content_backlog_id);
        $this->assertSame($primary->id,(int)$row->primary_seo_keyword_id);
        $this->assertSame(140,(int)$row->gsc_impressions);
        $this->assertSame(2,(int)$row->gsc_clicks);
        $this->assertSame(['irrigation tape'],json_decode($row->supporting_keywords,true));
        $this->assertSame('2026-10-09',$row->retest_after);
    }

    private function keyword(int $projectId,string $keyword): SeoKeyword
    {
        return SeoKeyword::create(['business_project_id'=>$projectId,'keyword'=>$keyword,
            'normalized_keyword'=>$keyword,'normalized_hash'=>hash('sha256',$keyword),'status'=>'active']);
    }
    private function metric(int $keywordId,string $url,int $impressions,int $clicks,float $position,string $date): void
    {
        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id'=>$keywordId,'business_project_site_id'=>null,'metric_date'=>$date,
            'page_url'=>$url,'country'=>'usa','device'=>'desktop','impressions'=>$impressions,
            'clicks'=>$clicks,'ctr'=>$impressions ? $clicks/$impressions : 0,
            'average_position'=>$position,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
