<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ArticleGuidelineService;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleGuidelineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guideline_contains_required_writer_contract_without_pre_writing_the_article(): void
    {
        $article = ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'guideline-test'),
            'source_system' => 'test',
            'source_ref' => 'guideline-test',
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'drip irrigation guide',
            'supporting_keywords' => ['drip irrigation parts', 'irrigation layout'],
            'search_intent' => 'informational',
            'topic_cluster' => 'Drip Irrigation',
            'title_direction' => 'Drip Irrigation Guide for Buyers',
            'evidence_summary' => ['gsc' => ['impressions' => 40, 'clicks' => 2]],
            'reference_sources' => [['source_type' => 'google_ads', 'source_key' => 'seed']],
            'authoring_mode' => 'human',
            'workflow_status' => ContentWorkflowStatus::WaitingForWriter,
        ]);

        $guideline = app(ArticleGuidelineService::class)->generate($article->id);

        foreach ([
            'primary_keyword',
            'supporting_keywords',
            'search_intent',
            'target_audience',
            'article_objective',
            'title_direction',
            'recommended_structure',
            'questions_to_answer',
            'recommended_word_count',
            'internal_links',
            'external_references',
            'available_evidence',
            'prohibited_claims',
            'data_requiring_verification',
            'brand_tone',
            'language',
            'cta_direction',
            'submission_instructions',
        ] as $key) {
            self::assertArrayHasKey($key, $guideline);
        }

        self::assertSame(1400, $guideline['recommended_word_count']);
        self::assertSame('drip irrigation guide', $guideline['primary_keyword']);
        self::assertArrayNotHasKey('article_body', $guideline);

        $again = app(ArticleGuidelineService::class)->generate($article->id);
        self::assertSame($guideline, $again);
        self::assertSame($guideline, $article->fresh()->guideline_json);
    }
}
