<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_base_persists_without_a_traffic_foreign_key(): void
    {
        $article = ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'yby-irrigation|ybyirrigation.com|seo_backlog|168'),
            'source_system' => 'seo_backlog',
            'source_ref' => '168',
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'example keyword',
            'supporting_keywords' => ['supporting keyword'],
            'title_direction' => 'Example title direction',
            'workflow_status' => ContentWorkflowStatus::ArticleBaseReady,
        ]);

        self::assertSame(ContentWorkflowStatus::ArticleBaseReady, $article->workflow_status);
        self::assertSame('168', $article->source_ref);
        self::assertSame(['supporting keyword'], $article->supporting_keywords);
    }
}
