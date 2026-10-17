<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\DraftPublishingAdapter;
use App\Modules\Content\Application\Contracts\PublishingAdapter;
use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Application\PublicationService;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_owner_gate_and_publish_are_deterministic_fail_closed_and_idempotent(): void
    {
        $user = User::factory()->create();
        $article = $this->article($user->id);
        config()->set('services.andy_core.url', '');

        $editorial = [
            'schema' => 'content-editorial-v1',
            'source' => ['type' => 'writer_original', 'version' => 1, 'hash' => str_repeat('a', 64)],
            'provider' => ['name' => 'fake-ai', 'model' => 'editorial-test'],
            'title' => 'Safe Editorial Title',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Selection <script>alert(1)</script>', 'items' => []],
                ['type' => 'paragraph', 'level' => null, 'text' => 'Choose based on verified project requirements.', 'items' => []],
                ['type' => 'bulleted_list', 'level' => null, 'text' => '', 'items' => ['Water source', 'Pressure & flow']],
            ],
            'change_summary' => ['Structured for publication.'],
            'verification_required' => [
                ['claim' => 'Exact service life', 'reason' => 'Not present in accepted evidence.'],
            ],
        ];
        $editorialJson = json_encode($editorial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $editorialId = DB::table('content_snapshots')->insertGetId([
            'article_id' => $article->id,
            'type' => 'editorial',
            'version' => 1,
            'normalized_json' => $editorialJson,
            'content_hash' => hash('sha256', $editorialJson),
            'created_by_type' => 'ai',
            'created_by_id' => 'fake-ai:editorial-test',
            'created_at' => now(),
        ]);

        $fakeDraft = new class implements DraftPublishingAdapter
        {
            public ?string $seenIdempotencyKey = null;

            public function draft(int $articleId, int $snapshotId, string $idempotencyKey): array
            {
                $this->seenIdempotencyKey = $idempotencyKey;

                return [
                    'provider' => 'andy_core',
                    'request_id' => 'req-c7-draft',
                    'external_id' => 'wp-post-77',
                    'status' => 'draft',
                    'url' => 'https://ybyirrigation.com/test-content/?preview=true',
                    'layout_snapshot_id' => $snapshotId,
                ];
            }
        };
        $this->app->instance(DraftPublishingAdapter::class, $fakeDraft);

        $service = app(PublicationService::class);
        self::assertSame(1, $service->preparePreview($article->id, $user->id));
        self::assertSame(1, $service->preparePreview($article->id, $user->id));
        self::assertSame(ContentWorkflowStatus::LayoutReady, $article->fresh()->workflow_status);
        self::assertDatabaseCount('content_publications', 1);
        self::assertDatabaseCount('content_snapshots', 2);

        $publication = DB::table('content_publications')->where('article_id', $article->id)->first();
        $preview = json_decode($publication->preview_json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($editorialId, $preview['source']['snapshot_id']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $preview['html']);
        self::assertStringNotContainsString('<script>', $preview['html']);
        self::assertSame(hash('sha256', json_encode(
            $preview,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )), $publication->preview_hash);
        self::assertNull($publication->verification_resolved_at);

        try {
            $service->submitOwnerUat($article->id, $user->id);
            self::fail('Expected unresolved verification to block Owner UAT.');
        } catch (PublicationException $exception) {
            self::assertSame('verification_required', $exception->reason);
        }

        self::assertSame(ContentWorkflowStatus::LayoutReady, $article->fresh()->workflow_status);
        $service->resolveVerification($article->id, $user->id);
        $service->submitOwnerUat($article->id, $user->id);
        self::assertSame(ContentWorkflowStatus::OwnerUat, $article->fresh()->workflow_status);
        self::assertNotEmpty($fakeDraft->seenIdempotencyKey);
        self::assertDatabaseHas('content_publications', [
            'article_id' => $article->id,
            'status' => 'owner_uat',
            'external_id' => 'wp-post-77',
            'provider_status' => 'draft',
        ]);

        try {
            $service->publish($article->id, $user->id);
            self::fail('Expected missing publication approvals to fail closed.');
        } catch (PublicationException $exception) {
            self::assertSame('publication_gates_required', $exception->reason);
        }

        $service->approveFact($article->id, $user->id);
        $service->approveSeo($article->id, $user->id);
        try {
            $service->publish($article->id, $user->id);
            self::fail('Expected Human mode to remain blocked until Writer PASS.');
        } catch (PublicationException $exception) {
            self::assertSame('publication_gates_required', $exception->reason);
        }
        $service->approveWriter($article->id, $user->id);
        self::assertDatabaseHas('content_publications', [
            'article_id' => $article->id,
            'writer_approved_by' => $user->id,
            'fact_approved_by' => $user->id,
            'seo_approved_by' => $user->id,
            'requested_indexing' => 'noindex',
        ]);

        config()->set('services.andy_core.url', '');
        config()->set('services.andy_core.shared_secret', '');

        try {
            $service->publish($article->id, $user->id);
            self::fail('Expected unconfigured Andy Core publishing to fail closed.');
        } catch (PublicationException $exception) {
            self::assertSame('andy_core_not_configured', $exception->reason);
        }

        self::assertSame(ContentWorkflowStatus::OwnerUat, $article->fresh()->workflow_status);
        $afterFailedPublish = DB::table('content_publications')->where('article_id', $article->id)->first();
        self::assertNotEmpty($afterFailedPublish->idempotency_key);
        $reservedIdempotencyKey = (string) $afterFailedPublish->idempotency_key;

        $fake = new class implements PublishingAdapter
        {
            public ?string $seenIdempotencyKey = null;

            public function preview(int $articleId, int $snapshotId): array
            {
                throw new \RuntimeException('Preview should not be called during publish retry.');
            }

            public function publish(int $articleId, int $snapshotId, string $idempotencyKey): array
            {
                $this->seenIdempotencyKey = $idempotencyKey;

                return [
                    'provider' => 'andy_core',
                    'request_id' => 'req-c7-test',
                    'external_id' => 'wp-post-77',
                    'status' => 'publish',
                    'url' => 'https://ybyirrigation.com/test-content/',
                    'layout_snapshot_id' => $snapshotId,
                ];
            }
        };
        $this->app->instance(PublishingAdapter::class, $fake);

        $result = app(PublicationService::class)->publish($article->id, $user->id);
        self::assertSame($reservedIdempotencyKey, $fake->seenIdempotencyKey);
        self::assertSame('wp-post-77', $result['external_id']);
        self::assertSame(ContentWorkflowStatus::Published, $article->fresh()->workflow_status);
        self::assertDatabaseHas('content_publications', [
            'article_id' => $article->id,
            'status' => 'published',
            'provider' => 'andy_core',
            'provider_request_id' => 'req-c7-test',
            'external_id' => 'wp-post-77',
            'published_url' => 'https://ybyirrigation.com/test-content/',
            'requested_indexing' => 'index',
        ]);
        self::assertDatabaseHas('content_article_events', [
            'article_id' => $article->id,
            'event_type' => 'content_published',
        ]);
    }

    public function test_ai_authoring_requires_fact_and_seo_but_not_fabricated_writer_pass(): void
    {
        $user = User::factory()->create();
        $article = ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'publication-ai-'.$user->id),
            'source_system' => 'test',
            'source_ref' => 'publication-ai-'.$user->id,
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'ai irrigation guide',
            'search_intent' => 'informational',
            'title_direction' => 'AI Irrigation Guide',
            'canonical_path' => '/blog/ai-irrigation-guide/',
            'authoring_mode' => ContentAuthoringMode::Ai,
            'workflow_status' => ContentWorkflowStatus::OwnerUat,
            'writer_id' => null,
        ]);
        $layoutJson = json_encode(['schema' => 'content-preview-v1'], JSON_THROW_ON_ERROR);
        $layoutHash = hash('sha256', $layoutJson);
        $layoutId = DB::table('content_snapshots')->insertGetId([
            'article_id' => $article->id,
            'type' => 'layout',
            'version' => 1,
            'normalized_json' => $layoutJson,
            'content_hash' => $layoutHash,
            'created_by_type' => 'system',
            'created_by_id' => '1',
            'created_at' => now(),
        ]);
        DB::table('content_publications')->insert([
            'article_id' => $article->id,
            'layout_snapshot_id' => $layoutId,
            'target_site' => 'ybyirrigation.com',
            'status' => 'owner_uat',
            'preview_hash' => $layoutHash,
            'preview_json' => $layoutJson,
            'provider' => 'andy_core',
            'external_id' => 'wp-ai-77',
            'provider_status' => 'draft',
            'draft_url' => 'https://ybyirrigation.com/ai-irrigation-guide/?preview=true',
            'draft_synced_at' => now(),
            'fact_approved_at' => now(),
            'fact_approved_by' => $user->id,
            'seo_approved_at' => now(),
            'seo_approved_by' => $user->id,
            'writer_approved_at' => null,
            'writer_approved_by' => null,
            'requested_indexing' => 'noindex',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fake = new class implements PublishingAdapter
        {
            public function preview(int $articleId, int $snapshotId): array { return []; }
            public function publish(int $articleId, int $snapshotId, string $idempotencyKey): array
            {
                return [
                    'provider' => 'andy_core',
                    'request_id' => 'req-ai-publish',
                    'external_id' => 'wp-ai-77',
                    'status' => 'publish',
                    'url' => 'https://ybyirrigation.com/blog/ai-irrigation-guide/',
                    'layout_snapshot_id' => $snapshotId,
                    'indexing' => 'index',
                ];
            }
        };
        $this->app->instance(PublishingAdapter::class, $fake);

        $result = app(PublicationService::class)->publish($article->id, $user->id);
        self::assertSame('wp-ai-77', $result['external_id']);
        self::assertSame(ContentWorkflowStatus::Published, $article->fresh()->workflow_status);
        self::assertDatabaseHas('content_publications', [
            'article_id' => $article->id,
            'status' => 'published',
            'writer_approved_at' => null,
            'requested_indexing' => 'index',
        ]);
    }

    private function article(int $userId): ContentArticle
    {
        return ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'publication-'.$userId),
            'source_system' => 'test',
            'source_ref' => 'publication-'.$userId,
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'drip irrigation guide',
            'search_intent' => 'informational',
            'title_direction' => 'Drip Irrigation Guide',
            'canonical_path' => '/blog/drip-irrigation-guide/',
            'authoring_mode' => ContentAuthoringMode::Human,
            'workflow_status' => ContentWorkflowStatus::EditorialReady,
            'writer_id' => $userId,
        ]);
    }
}
