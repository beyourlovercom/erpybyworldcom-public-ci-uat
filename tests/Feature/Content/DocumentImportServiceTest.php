<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\DocumentImportService;
use App\Modules\Content\Application\Exceptions\DocumentImportException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DocumentImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_doc_submission_preserves_immutable_writer_original_and_explicit_reimport_versions(): void
    {
        config()->set('services.google_docs', [
            'client_id' => 'client',
            'client_secret' => 'secret',
            'refresh_token' => 'refresh',
            'docs_base_url' => 'https://docs.googleapis.com/v1',
            'drive_base_url' => 'https://www.googleapis.com/drive/v3',
            'oauth_url' => 'https://oauth2.googleapis.com/token',
            'timeout' => 5,
        ]);

        $phase = 1;
        Http::fake(function ($request) use (&$phase) {
            $url = $request->url();

            if ($url === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'token'], 200);
            }

            if (str_contains($url, 'docs.googleapis.com/v1/documents/DocAbc123456')) {
                return Http::response([
                    'documentId' => 'DocAbc123456',
                    'title' => 'Writer draft',
                    'revisionId' => 'rev-'.$phase,
                    'body' => [
                        'content' => [
                            ['paragraph' => [
                                'paragraphStyle' => ['namedStyleType' => 'HEADING_1'],
                                'elements' => [['textRun' => ['content' => "Drip Irrigation Guide\n", 'textStyle' => ['bold' => true]]]],
                            ]],
                            ['paragraph' => [
                                'paragraphStyle' => ['namedStyleType' => 'NORMAL_TEXT'],
                                'elements' => [['textRun' => ['content' => ($phase === 1 ? 'Original writer paragraph.' : 'Revised writer paragraph.')."\n"]]],
                            ]],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'www.googleapis.com/drive/v3/files/DocAbc123456')) {
                return Http::response([
                    'id' => 'DocAbc123456',
                    'name' => 'Writer draft',
                    'mimeType' => 'application/vnd.google-apps.document',
                    'modifiedTime' => $phase === 1 ? '2026-09-22T03:00:00.000Z' : '2026-09-22T04:00:00.000Z',
                    'version' => (string) $phase,
                    'webViewLink' => 'https://docs.google.com/document/d/DocAbc123456/edit',
                ], 200);
            }

            return Http::response([], 404);
        });

        $writer = User::factory()->create();
        $article = $this->article($writer->id);

        $service = app(DocumentImportService::class);
        $version = $service->submit(
            $article->id,
            $writer->id,
            'https://docs.google.com/document/d/DocAbc123456/edit',
        );

        self::assertSame(1, $version);
        $article->refresh();
        self::assertSame(ContentWorkflowStatus::DocImported, $article->workflow_status);
        self::assertDatabaseCount('content_document_sources', 1);
        $source = DB::table('content_document_sources')->where('article_id', $article->id)->first();
        self::assertSame('2026-09-22 03:00:00', (string) $source->modified_time);
        self::assertDatabaseHas('content_snapshots', [
            'article_id' => $article->id,
            'type' => 'writer_original',
            'version' => 1,
            'created_by_id' => (string) $writer->id,
        ]);

        $v1 = DB::table('content_snapshots')
            ->where('article_id', $article->id)
            ->where('type', 'writer_original')
            ->where('version', 1)
            ->first();

        $phase = 2;
        self::assertTrue($service->checkChanged($article->id, $writer->id));
        self::assertDatabaseHas('content_document_sources', [
            'article_id' => $article->id,
            'changed_after_submission' => 1,
        ]);

        $version2 = $service->reimport($article->id, $writer->id);
        self::assertSame(2, $version2);
        self::assertDatabaseCount('content_snapshots', 2);

        $v1After = DB::table('content_snapshots')->where('id', $v1->id)->first();
        self::assertSame($v1->content_hash, $v1After->content_hash);
        self::assertSame($v1->normalized_json, $v1After->normalized_json);
        self::assertDatabaseHas('content_document_sources', [
            'article_id' => $article->id,
            'revision_id' => 'rev-2',
            'changed_after_submission' => 0,
        ]);
        self::assertDatabaseHas('content_article_events', [
            'article_id' => $article->id,
            'event_type' => 'document_reimported',
            'actor_id' => $writer->id,
        ]);
    }

    public function test_invalid_google_doc_url_is_rejected_without_state_change(): void
    {
        config()->set('services.google_docs', [
            'client_id' => 'client',
            'client_secret' => 'secret',
            'refresh_token' => 'refresh',
        ]);

        $writer = User::factory()->create();
        $article = $this->article($writer->id);

        try {
            app(DocumentImportService::class)->submit($article->id, $writer->id, 'https://example.com/not-a-google-doc');
            self::fail('Expected invalid Google Docs URL rejection.');
        } catch (DocumentImportException $exception) {
            self::assertSame('invalid_document_url', $exception->reason);
        }

        self::assertSame(ContentWorkflowStatus::Writing, $article->fresh()->workflow_status);
        self::assertDatabaseCount('content_snapshots', 0);
        self::assertDatabaseCount('content_document_sources', 0);
    }

    private function article(int $writerId): ContentArticle
    {
        return ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'doc-import-'.$writerId),
            'source_system' => 'test',
            'source_ref' => 'doc-import-'.$writerId,
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'ybyirrigation.com',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'drip irrigation guide',
            'title_direction' => 'Drip Irrigation Guide',
            'authoring_mode' => 'human',
            'workflow_status' => ContentWorkflowStatus::Writing,
            'writer_id' => $writerId,
        ]);
    }
}
