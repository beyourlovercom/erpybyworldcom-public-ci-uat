<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\EditorialAiClient;
use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Data\EditorialResultData;
use App\Modules\Content\Application\EditorialService;
use App\Modules\Content\Application\Exceptions\EditorialException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EditorialServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_editorial_creates_separate_snapshot_and_is_idempotent_for_same_source(): void
    {
        $writer = User::factory()->create();
        $article = $this->article($writer->id);
        $source = ['schema' => 'google-docs-normalized-v1', 'blocks' => [['type' => 'paragraph', 'text' => 'Writer facts only.']]];
        $sourceJson = json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sourceHash = hash('sha256', $sourceJson);

        DB::table('content_snapshots')->insert([
            'article_id' => $article->id,
            'type' => 'writer_original',
            'version' => 1,
            'normalized_json' => $sourceJson,
            'content_hash' => $sourceHash,
            'created_by_type' => 'user',
            'created_by_id' => (string) $writer->id,
            'created_at' => now(),
        ]);

        $fake = new class implements EditorialAiClient
        {
            public int $calls = 0;

            public function available(): bool
            {
                return true;
            }

            public function edit(EditorialRequestData $request): EditorialResultData
            {
                $this->calls++;

                return new EditorialResultData(
                    provider: 'fake-ai',
                    model: 'editorial-test',
                    title: 'Edited title',
                    blocks: [['type' => 'paragraph', 'level' => null, 'text' => 'Writer facts only, edited.', 'items' => []]],
                    changeSummary: ['Improved clarity.'],
                    verificationRequired: [['claim' => 'Any exact performance number', 'reason' => 'Not present in accepted source.']],
                );
            }
        };
        $this->app->instance(EditorialAiClient::class, $fake);

        $service = app(EditorialService::class);
        self::assertSame(1, $service->run($article->id, $writer->id));
        self::assertSame(1, $fake->calls);
        self::assertSame(ContentWorkflowStatus::EditorialReady, $article->fresh()->workflow_status);
        self::assertDatabaseCount('content_snapshots', 2);

        $writerOriginal = DB::table('content_snapshots')
            ->where('article_id', $article->id)->where('type', 'writer_original')->first();
        self::assertSame($sourceHash, $writerOriginal->content_hash);
        self::assertSame($sourceJson, $writerOriginal->normalized_json);

        $editorial = DB::table('content_snapshots')
            ->where('article_id', $article->id)->where('type', 'editorial')->first();
        $payload = json_decode($editorial->normalized_json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('content-editorial-v1', $payload['schema']);
        self::assertSame('writer_original', $payload['source']['type']);
        self::assertSame(1, $payload['source']['version']);
        self::assertSame($sourceHash, $payload['source']['hash']);
        self::assertSame('fake-ai', $payload['provider']['name']);

        self::assertSame(1, $service->run($article->id, $writer->id));
        self::assertSame(1, $fake->calls);
        self::assertDatabaseCount('content_snapshots', 2);
        self::assertDatabaseHas('content_article_events', ['article_id' => $article->id, 'event_type' => 'editorial_ready']);
    }

    public function test_provider_failure_leaves_accepted_source_and_workflow_unchanged(): void
    {
        $writer = User::factory()->create();
        $article = $this->article($writer->id);
        $source = ['schema' => 'google-docs-normalized-v1', 'blocks' => []];
        $sourceJson = json_encode($source, JSON_THROW_ON_ERROR);
        DB::table('content_snapshots')->insert([
            'article_id' => $article->id,
            'type' => 'writer_original',
            'version' => 1,
            'normalized_json' => $sourceJson,
            'content_hash' => hash('sha256', $sourceJson),
            'created_by_type' => 'user',
            'created_by_id' => (string) $writer->id,
            'created_at' => now(),
        ]);

        $this->app->instance(EditorialAiClient::class, new class implements EditorialAiClient
        {
            public function available(): bool
            {
                return true;
            }

            public function edit(EditorialRequestData $request): EditorialResultData
            {
                throw new EditorialException('provider_request_failed', 'Synthetic provider failure.');
            }
        });

        try {
            app(EditorialService::class)->run($article->id, $writer->id);
            self::fail('Expected provider failure.');
        } catch (EditorialException $exception) {
            self::assertSame('provider_request_failed', $exception->reason);
        }

        self::assertSame(ContentWorkflowStatus::DocImported, $article->fresh()->workflow_status);
        self::assertDatabaseCount('content_snapshots', 1);
        self::assertDatabaseMissing('content_snapshots', ['article_id' => $article->id, 'type' => 'editorial']);
    }

    private function article(int $writerId): ContentArticle
    {
        return ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'editorial-'.$writerId),
            'source_system' => 'test',
            'source_ref' => 'editorial-'.$writerId,
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'drip irrigation guide',
            'title_direction' => 'Drip Irrigation Guide',
            'authoring_mode' => ContentAuthoringMode::Human,
            'workflow_status' => ContentWorkflowStatus::DocImported,
            'writer_id' => $writerId,
            'guideline_json' => [
                'version' => 'CONTENT-GUIDELINE-V1',
                'brand_tone' => 'Professional and evidence-led.',
                'prohibited_claims' => ['Do not invent specifications.'],
            ],
        ]);
    }
}
