<?php

namespace Tests\Feature\Content;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Content\Application\Exceptions\ContentSourceRejected;
use App\Modules\Content\Infrastructure\Yby\YbySeoContentSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class YbySeoContentSourceAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_planned_blog_becomes_a_portable_article_base_with_existing_seo_evidence(): void
    {
        [$project, $keywordId, $backlogId] = $this->source('blog');

        DB::table('seo_content_backlog')->insert([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $this->keyword($project->id, 'drip irrigation parts'),
            'topic_cluster' => 'Drip Irrigation',
            'content_type' => 'cluster_member',
            'priority' => 'P2',
            'status' => 'retired',
            'title' => 'drip irrigation parts',
            'target_url' => null,
            'rationale' => 'Merged into target backlog #'.$backlogId.'.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_gsc_daily_metrics')->insert([
            'seo_keyword_id' => $keywordId,
            'business_project_site_id' => null,
            'metric_date' => '2026-09-21',
            'page_url' => null,
            'country' => 'usa',
            'device' => 'DESKTOP',
            'impressions' => 40,
            'clicks' => 2,
            'ctr' => 0.05,
            'average_position' => 14.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('seo_keyword_evidence')->insert([
            'seo_keyword_id' => $keywordId,
            'source_type' => 'google_ads',
            'source_key' => 'seed',
            'impressions' => 100,
            'clicks' => 5,
            'conversions' => 1,
            'cost' => 20,
            'currency_code' => 'USD',
            'average_position' => null,
            'search_volume' => null,
            'competition' => null,
            'landing_page_url' => null,
            'dimensions' => null,
            'raw_payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $base = app(YbySeoContentSourceAdapter::class)->createArticleBase((string) $backlogId);

        self::assertSame('yby_seo_content_backlog', $base->sourceSystem);
        self::assertSame('yby-irrigation', $base->projectKey);
        self::assertSame('ybyirrigation.com', $base->targetSite);
        self::assertSame('blog', $base->contentType);
        self::assertSame('drip irrigation guide', $base->primaryKeyword);
        self::assertSame(['drip irrigation parts'], $base->supportingKeywords);
        self::assertSame(40, $base->evidenceSummary['gsc']['impressions']);
        self::assertSame(14.5, $base->evidenceSummary['gsc']['average_position']);
        self::assertSame('google_ads', $base->referenceSources[0]['source_type']);
    }

    public function test_comparison_blog_maps_to_comparison(): void
    {
        [, , $backlogId] = $this->source('comparison_blog');

        $base = app(YbySeoContentSourceAdapter::class)->createArticleBase((string) $backlogId);

        self::assertSame('comparison', $base->contentType);
    }

    public function test_product_canonical_source_is_rejected_from_writer_content_flow(): void
    {
        [, , $backlogId] = $this->source('commercial_page');

        try {
            app(YbySeoContentSourceAdapter::class)->createArticleBase((string) $backlogId);
            self::fail('Expected product flow rejection.');
        } catch (ContentSourceRejected $exception) {
            self::assertSame('product_flow_required', $exception->reason);
        }
    }

    public function test_non_planned_backlog_is_rejected(): void
    {
        [, , $backlogId] = $this->source('blog', 'published');

        try {
            app(YbySeoContentSourceAdapter::class)->createArticleBase((string) $backlogId);
            self::fail('Expected source state rejection.');
        } catch (ContentSourceRejected $exception) {
            self::assertSame('source_not_planned', $exception->reason);
        }
    }

    /** @return array{BusinessProject, int, int} */
    private function source(string $contentType, string $status = 'planned'): array
    {
        $project = BusinessProject::query()->create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        DB::table('business_project_sites')->insert([
            'business_project_id' => $project->id,
            'site_key' => 'new',
            'domain_normalized' => 'ybyirrigation.com',
            'base_url' => 'https://ybyirrigation.com',
            'status' => 'active',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keywordId = $this->keyword($project->id, 'drip irrigation guide');

        $backlogId = DB::table('seo_content_backlog')->insertGetId([
            'business_project_id' => $project->id,
            'seo_keyword_id' => $keywordId,
            'topic_cluster' => 'Drip Irrigation',
            'content_type' => $contentType,
            'priority' => 'P0',
            'status' => $status,
            'title' => 'Drip Irrigation Guide',
            'target_url' => null,
            'rationale' => 'NEW_CONTENT_P0; selected by SEO-7.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$project, $keywordId, $backlogId];
    }

    private function keyword(int $projectId, string $keyword): int
    {
        return DB::table('seo_keywords')->insertGetId([
            'business_project_id' => $projectId,
            'keyword' => $keyword,
            'normalized_keyword' => strtolower($keyword),
            'normalized_hash' => hash('sha256', strtolower($keyword).uniqid('', true)),
            'topic_cluster' => 'Drip Irrigation',
            'intent' => 'informational',
            'suggested_target_type' => 'Blog',
            'suggested_action' => 'NEW_CONTENT_P0',
            'opportunity_score' => 42.5,
            'is_competitor_term' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
