<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoOpportunityRefeedService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoOpportunityRefeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_gsc_only_query_cannot_be_promoted_to_p0_without_commercial_evidence(): void
    {
        [$project, $keyword] = $this->keyword(null);
        $this->metric($keyword->id, 100, 0, 15.0);
        $result = app(SeoOpportunityRefeedService::class)->run($project->project_key, 'dry_run');
        $this->assertSame(['RESEARCH_P2' => 1], $result['actions']);
    }

    public function test_paid_evidence_allows_page1_p0_and_curated_actions_are_not_overwritten(): void
    {
        [$project, $keyword] = $this->keyword(null);
        $this->metric($keyword->id, 100, 3, 15.0);
        DB::table('seo_keyword_evidence')->insert(['seo_keyword_id'=>$keyword->id,'source_type'=>'google_ads','source_key'=>'test','impressions'=>100,'clicks'=>1,'conversions'=>0,'cost'=>10,'created_at'=>now(),'updated_at'=>now()]);
        app(SeoOpportunityRefeedService::class)->run($project->project_key, 'apply');
        $this->assertSame('PAGE1_P0', $keyword->fresh()->suggested_action);
        [$p2, $curated] = $this->keyword('DEFEND', 'curated-keyword');
        $this->metric($curated->id, 500, 0, 15.0);
        app(SeoOpportunityRefeedService::class)->run($p2->project_key, 'apply');
        $this->assertSame('DEFEND', $curated->fresh()->suggested_action);
    }
    private function keyword(?string $action, string $keyword='test-keyword'): array
    {
        $project=BusinessProject::firstOrCreate(['project_key'=>'yby-irrigation'],['name'=>'YBY Irrigation','status'=>'active']);
        $model=SeoKeyword::create(['business_project_id'=>$project->id,'keyword'=>$keyword,'normalized_keyword'=>$keyword,'normalized_hash'=>hash('sha256',$keyword),'suggested_action'=>$action,'status'=>'active']);
        return [$project,$model];
    }

    private function metric(int $keywordId, int $impressions, int $clicks, float $position): void
    {
        DB::table('seo_gsc_daily_metrics')->insert(['seo_keyword_id'=>$keywordId,'business_project_site_id'=>null,'metric_date'=>'2026-09-10','page_url'=>'https://example.com/','country'=>'usa','device'=>'desktop','impressions'=>$impressions,'clicks'=>$clicks,'ctr'=>$impressions ? $clicks/$impressions : 0,'average_position'=>$position,'created_at'=>now(),'updated_at'=>now()]);
    }
}
