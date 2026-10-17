<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\SeoPostPublishMeasurementService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPostPublishMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_publication_and_captures_legacy_and_published_snapshots(): void
    {
        $project = BusinessProject::create([
            'project_key'=>'yby-irrigation','name'=>'YBY Irrigation','status'=>'active'
        ]);
        $keyword = SeoKeyword::create([
            'business_project_id'=>$project->id,'keyword'=>'drip tape',
            'normalized_keyword'=>'drip tape','normalized_hash'=>hash('sha256','drip tape'),
            'status'=>'active',
        ]);

        $legacy='https://www.yby-irrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html';
        $published='https://ybyirrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html';
        DB::table('seo_optimization_work_orders')->insert([
            'business_project_id'=>$project->id,
            'seo_content_backlog_id'=>null,
            'primary_seo_keyword_id'=>$keyword->id,
            'target_url_hash'=>hash('sha256',$legacy),
            'target_url'=>$legacy,
            'supporting_keywords'=>json_encode([]),
            'gsc_impressions'=>100,'gsc_clicks'=>2,'gsc_ctr'=>0.02,
            'gsc_average_position'=>8.5,'priority_score'=>100,
            'recommendations'=>json_encode(['production'=>[
                'post_id'=>762,'publish_date'=>'2026-09-18','retest_after'=>'2026-10-16',
            ]]),
            'workflow_status'=>'published',
            'baseline_from'=>'2026-08-20','baseline_to'=>'2026-09-14','retest_after'=>'2026-10-16',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        foreach ([[$legacy,30,1,7.0],[$published,20,2,6.0]] as [$url,$impressions,$clicks,$pos]) {
            DB::table('seo_gsc_daily_metrics')->insert([
                'seo_keyword_id'=>$keyword->id,'business_project_site_id'=>null,
                'metric_date'=>'2026-09-20','page_url'=>$url,'country'=>'usa','device'=>'desktop',
                'impressions'=>$impressions,'clicks'=>$clicks,'ctr'=>$clicks/$impressions,
                'average_position'=>$pos,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $service=app(SeoPostPublishMeasurementService::class);
        $register=$service->register('yby-irrigation','apply');
        $snapshot=$service->snapshot('yby-irrigation','T28','apply');
        $again=$service->snapshot('yby-irrigation','T28','apply');

        $this->assertSame(1,$register['applied']);
        $this->assertSame(2,$snapshot['applied']);
        $this->assertSame(2,$again['applied']);
        $this->assertDatabaseCount('seo_optimization_publications',1);
        $this->assertDatabaseCount('seo_optimization_measurements',2);

        $publication=DB::table('seo_optimization_publications')->first();
        $this->assertSame($legacy,$publication->baseline_url);
        $this->assertSame($published,$publication->published_url);
        $this->assertSame(762,(int)$publication->production_post_id);

        $legacyRow=DB::table('seo_optimization_measurements')
            ->where('url_role','legacy')->first();
        $publishedRow=DB::table('seo_optimization_measurements')
            ->where('url_role','published')->first();

        $this->assertSame(30,(int)$legacyRow->impressions);
        $this->assertSame(20,(int)$publishedRow->impressions);
        $this->assertSame(2,(int)$publishedRow->clicks);
        $this->assertSame('2026-09-20',$publishedRow->metric_from);
        $this->assertSame('2026-09-20',$publishedRow->metric_to);
    }

    public function test_register_and_snapshot_can_scope_to_one_work_order(): void
    {
        $project = BusinessProject::create([
            'project_key'=>'yby-irrigation','name'=>'YBY Irrigation','status'=>'active'
        ]);
        $keywordA = SeoKeyword::create([
            'business_project_id'=>$project->id,'keyword'=>'canvas hose',
            'normalized_keyword'=>'canvas hose','normalized_hash'=>hash('sha256','canvas hose'),
            'status'=>'active',
        ]);
        $keywordB = SeoKeyword::create([
            'business_project_id'=>$project->id,'keyword'=>'rain hose',
            'normalized_keyword'=>'rain hose','normalized_hash'=>hash('sha256','rain hose'),
            'status'=>'active',
        ]);
        $orders = [];
        foreach ([
            [$keywordA->id,'https://legacy.example/canvas-hose','https://example.com/canvas-hose/',794],
            [$keywordB->id,'https://legacy.example/rain-hose','https://example.com/rain-hose/',795],
        ] as [$keywordId,$legacy,$published,$postId]) {
            $orders[] = DB::table('seo_optimization_work_orders')->insertGetId([
                'business_project_id'=>$project->id,'seo_content_backlog_id'=>null,
                'primary_seo_keyword_id'=>$keywordId,'target_url_hash'=>hash('sha256',$legacy),
                'target_url'=>$legacy,'supporting_keywords'=>json_encode([]),
                'gsc_impressions'=>40,'gsc_clicks'=>0,'gsc_ctr'=>0,
                'gsc_average_position'=>7.5,'priority_score'=>40,
                'recommendations'=>json_encode(['production'=>[
                    'post_id'=>$postId,'publish_date'=>'2026-09-20','retest_after'=>'2026-10-18',
                    'canonical_url'=>$published,
                ]]),
                'workflow_status'=>'published','baseline_from'=>'2026-09-01',
                'baseline_to'=>'2026-09-14','retest_after'=>'2026-10-18',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }

        $service=app(SeoPostPublishMeasurementService::class);
        $registerA=$service->register('yby-irrigation','apply',$orders[0]);

        $this->assertSame($orders[0],$registerA['work_order_id']);
        $this->assertSame(1,$registerA['seen']);
        $this->assertSame(1,$registerA['applied']);
        $this->assertDatabaseCount('seo_optimization_publications',1);
        $this->assertDatabaseHas('seo_optimization_publications',[
            'seo_optimization_work_order_id'=>$orders[0],'production_post_id'=>794,
        ]);
        $this->assertDatabaseMissing('seo_optimization_publications',[
            'seo_optimization_work_order_id'=>$orders[1],
        ]);

        $service->register('yby-irrigation','apply',$orders[1]);

        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id'=>$keywordA->id,'business_project_site_id'=>null,
            'metric_date'=>'2026-09-20','page_url'=>'https://example.com/canvas-hose/',
            'country'=>'usa','device'=>'desktop','impressions'=>12,'clicks'=>1,
            'ctr'=>1/12,'average_position'=>8.0,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $snapshotA=$service->snapshot('yby-irrigation','T0','apply',$orders[0]);
        $this->assertSame($orders[0],$snapshotA['work_order_id']);
        $this->assertSame(2,$snapshotA['seen']);
        $this->assertSame(2,$snapshotA['applied']);

        $publicationA=DB::table('seo_optimization_publications')
            ->where('seo_optimization_work_order_id',$orders[0])->first();
        $publicationB=DB::table('seo_optimization_publications')
            ->where('seo_optimization_work_order_id',$orders[1])->first();

        $this->assertSame(2,DB::table('seo_optimization_measurements')
            ->where('seo_optimization_publication_id',$publicationA->id)->count());
        $this->assertSame(0,DB::table('seo_optimization_measurements')
            ->where('seo_optimization_publication_id',$publicationB->id)->count());
    }

    public function test_register_prefers_explicit_production_canonical_url(): void
    {
        $project = BusinessProject::create([
            'project_key'=>'yby-irrigation','name'=>'YBY Irrigation','status'=>'active'
        ]);
        $keyword = SeoKeyword::create([
            'business_project_id'=>$project->id,'keyword'=>'rain hose',
            'normalized_keyword'=>'rain hose','normalized_hash'=>hash('sha256','rain hose'),
            'status'=>'active',
        ]);
        $legacy='https://www.yby-irrigation.com/farm-irrigation-system/rain-hose-irrigation.html';
        $canonical='https://ybyirrigation.com/spray-irrigation/sprinkler-hose-with-holes.html';

        DB::table('seo_optimization_work_orders')->insert([
            'business_project_id'=>$project->id,'seo_content_backlog_id'=>null,
            'primary_seo_keyword_id'=>$keyword->id,'target_url_hash'=>hash('sha256',$legacy),
            'target_url'=>$legacy,'supporting_keywords'=>json_encode([]),
            'gsc_impressions'=>10,'gsc_clicks'=>0,'gsc_ctr'=>0,
            'gsc_average_position'=>12.0,'priority_score'=>10,
            'recommendations'=>json_encode(['production'=>[
                'post_id'=>777,'publish_date'=>'2026-09-19','retest_after'=>'2026-10-17',
                'canonical_url'=>$canonical,
            ]]),
            'workflow_status'=>'published','baseline_from'=>'2026-08-20',
            'baseline_to'=>'2026-09-18','retest_after'=>'2026-10-17',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $result=app(SeoPostPublishMeasurementService::class)
            ->register('yby-irrigation','apply');

        $this->assertSame(1,$result['applied']);
        $publication=DB::table('seo_optimization_publications')->first();
        $this->assertSame($legacy,$publication->baseline_url);
        $this->assertSame($canonical,$publication->published_url);
        $this->assertSame(777,(int)$publication->production_post_id);
    }
}
