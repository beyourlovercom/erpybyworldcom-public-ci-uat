<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentProductionService;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\ContentSourceAdapter;
use App\Modules\Content\Application\Data\ArticleBaseData;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentProductionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_base_creation_is_idempotent_by_source_identity(): void
    {
        $source = new class implements ContentSourceAdapter {
            public function createArticleBase(string $sourceRef): ArticleBaseData
            {
                return new ArticleBaseData(
                    sourceSystem: 'seo_backlog',
                    sourceRef: $sourceRef,
                    projectKey: 'yby-irrigation',
                    targetSite: 'ybyirrigation.com',
                    language: 'en',
                    contentType: 'blog',
                    titleDirection: 'A practical irrigation guide',
                    primaryKeyword: 'irrigation guide',
                );
            }
        };

        $service = app(ContentProductionService::class);
        $first = $service->createArticleBase($source, '168');
        $second = $service->createArticleBase($source, '168');

        self::assertSame($first, $second);
        self::assertSame(1, ContentArticle::query()->count());
    }

    public function test_human_mode_gate_is_persisted_once(): void
    {
        $article = ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'source'),
            'source_system' => 'manual',
            'source_ref' => 'source',
            'project_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'title_direction' => 'Example',
            'workflow_status' => ContentWorkflowStatus::ArticleBaseReady,
        ]);

        $service = app(ContentProductionService::class);
        $next = $service->chooseAuthoringMode($article->id, ContentAuthoringMode::Human);

        self::assertSame(ContentWorkflowStatus::WaitingForWriter, $next);
        $article->refresh();
        self::assertSame(ContentAuthoringMode::Human, $article->authoring_mode);
        self::assertSame(ContentWorkflowStatus::WaitingForWriter, $article->workflow_status);

        $this->expectException(DomainException::class);
        $service->chooseAuthoringMode($article->id, ContentAuthoringMode::Ai);
    }
}
