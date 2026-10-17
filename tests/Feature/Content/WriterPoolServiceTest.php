<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\WriterPoolService;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WriterPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_is_limited_to_writer_project_and_language_scope(): void
    {
        $writer = User::factory()->create();
        $this->scope($writer->id, 'yby-irrigation', 'en');
        $allowed = $this->article('yby-irrigation', 'en', 'Allowed article');
        $this->article('yby-bottle', 'en', 'Wrong project');
        $this->article('yby-irrigation', 'fr', 'Wrong language');

        $pool = app(WriterPoolService::class)->pool($writer->id);

        self::assertSame([$allowed->id], array_column($pool, 'id'));
    }

    public function test_claim_next_is_single_owner_and_prevents_duplicate_claim(): void
    {
        $writerA = User::factory()->create();
        $writerB = User::factory()->create();
        $this->scope($writerA->id, 'yby-irrigation', 'en');
        $this->scope($writerB->id, 'yby-irrigation', 'en');
        $article = $this->article();

        $first = app(WriterPoolService::class)->claimNext($writerA->id);
        $second = app(WriterPoolService::class)->claimNext($writerB->id);

        self::assertSame($article->id, $first);
        self::assertNull($second);

        $article->refresh();
        self::assertSame($writerA->id, (int) $article->writer_id);
        self::assertSame(ContentWorkflowStatus::WriterClaimed, $article->workflow_status);
        self::assertNotNull($article->claim_expires_at);
        self::assertDatabaseHas('content_article_events', [
            'article_id' => $article->id,
            'event_type' => 'writer_claimed',
            'actor_id' => $writerA->id,
        ]);
    }

    public function test_expired_unstarted_claim_can_be_reclaimed(): void
    {
        $writerA = User::factory()->create();
        $writerB = User::factory()->create();
        $this->scope($writerB->id, 'yby-irrigation', 'en');

        $article = $this->article();
        $article->forceFill([
            'writer_id' => $writerA->id,
            'workflow_status' => ContentWorkflowStatus::WriterClaimed,
            'claimed_at' => now()->subDays(2),
            'claim_expires_at' => now()->subHour(),
        ])->save();

        $claimed = app(WriterPoolService::class)->claimNext($writerB->id);

        self::assertSame($article->id, $claimed);
        $article->refresh();
        self::assertSame($writerB->id, (int) $article->writer_id);
        self::assertDatabaseHas('content_article_events', [
            'article_id' => $article->id,
            'event_type' => 'claim_expired',
        ]);
    }

    public function test_writer_can_start_then_release_before_submission(): void
    {
        $writer = User::factory()->create();
        $this->scope($writer->id, 'yby-irrigation', 'en');
        $article = $this->article();

        $service = app(WriterPoolService::class);
        self::assertSame($article->id, $service->claimNext($writer->id));

        $service->startWriting($article->id, $writer->id);
        $article->refresh();
        self::assertSame(ContentWorkflowStatus::Writing, $article->workflow_status);
        self::assertNull($article->claim_expires_at);

        $service->release($article->id, $writer->id);
        $article->refresh();
        self::assertSame(ContentWorkflowStatus::WaitingForWriter, $article->workflow_status);
        self::assertNull($article->writer_id);
        self::assertSame(3, DB::table('content_article_events')->where('article_id', $article->id)->count());
    }

    private function scope(int $userId, string $projectKey, string $language): void
    {
        DB::table('content_writer_scopes')->insert([
            'user_id' => $userId,
            'project_key' => $projectKey,
            'language' => $language,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function article(
        string $projectKey = 'yby-irrigation',
        string $language = 'en',
        string $title = 'Writer pool article',
    ): ContentArticle {
        return ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', $projectKey.'|'.$language.'|'.$title),
            'source_system' => 'test',
            'source_ref' => uniqid('source-', true),
            'project_key' => $projectKey,
            'brand_key' => 'yby',
            'target_site' => 'ybyirrigation.com',
            'language' => $language,
            'content_type' => 'blog',
            'primary_keyword' => 'irrigation',
            'title_direction' => $title,
            'authoring_mode' => 'human',
            'workflow_status' => ContentWorkflowStatus::WaitingForWriter,
        ]);
    }
}
