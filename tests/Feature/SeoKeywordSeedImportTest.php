<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoKeywordSeedImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_summarizes_keyword_seed_without_writes(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
        $file = $this->seedFile();

        $this->artisan('traffic:seo-keywords:import', [
            '--project' => 'yby-irrigation',
            '--file' => $file,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('seo_keywords', 0);
        $this->assertDatabaseCount('seo_keyword_evidence', 0);
    }

    public function test_apply_imports_canonical_keyword_and_channel_evidence_idempotently(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
        $file = $this->seedFile();

        foreach ([1, 2] as $run) {
            $this->artisan('traffic:seo-keywords:import', [
                '--project' => 'yby-irrigation',
                '--file' => $file,
                '--apply' => true,
            ])->assertSuccessful();
        }

        $this->assertDatabaseCount('seo_keywords', 2);
        $this->assertDatabaseCount('seo_keyword_evidence', 6);
        $this->assertDatabaseHas('seo_keywords', [
            'normalized_keyword' => 'drip irrigation system',
            'topic_cluster' => 'Drip Irrigation System',
            'suggested_action' => 'PAGE1_P0',
        ]);
        $keywordId = DB::table('seo_keywords')
            ->where('normalized_keyword', 'drip irrigation system')
            ->value('id');

        $this->assertDatabaseHas('seo_keyword_evidence', [
            'seo_keyword_id' => $keywordId,
            'source_type' => 'google_ads',
            'clicks' => 758,
        ]);
        $this->assertDatabaseHas('seo_keyword_evidence', [
            'seo_keyword_id' => $keywordId,
            'source_type' => 'google_search_console',
            'impressions' => 1172,
        ]);
    }

    private function seedFile(): string
    {
        $file = storage_path('framework/testing/yby-keyword-seed.csv');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        $handle = fopen($file, 'wb');
        fputcsv($handle, $this->header());
        fputcsv($handle, $this->rowOne());
        fputcsv($handle, $this->rowTwo());
        fclose($handle);

        return $file;
    }

    /** @return list<string> */
    private function header(): array
    {
        return [
            'keyword', 'topic_cluster', 'suggested_action', 'suggested_target', 'competitor_term',
            'ads_clicks', 'ads_impressions', 'ads_conversions', 'ads_cost', 'ads_cpa',
            'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_avg_position',
            'alibaba_impressions', 'alibaba_clicks', 'evidence_sources', 'opportunity_score',
        ];
    }

    /** @return list<string|int|float> */
    private function rowOne(): array
    {
        return [
            'drip irrigation system', 'Drip Irrigation System', 'PAGE1_P0', 'Product/Category Page', 'no',
            758, 4034, 10, 1584.90, 158.49, 3, 1172, 0.00256, 37.19,
            38862, 905, 'ads,gsc,alibaba', 80.00,
        ];
    }

    /** @return list<string|int|float> */
    private function rowTwo(): array
    {
        return [
            'drip tape', 'Drip Tape', 'TOP3_CTR_P0', 'Product/Category Page', 'no',
            30, 292, 0.17, 116.18, 683.41, 66, 45888, 0.00144, 7.48,
            1411, 51, 'ads,gsc,alibaba', 70.00,
        ];
    }
}
