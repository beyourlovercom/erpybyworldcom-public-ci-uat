<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Infrastructure\AndyCoreContentPublishingAdapter;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AndyCoreContentPublishingAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_uses_exact_content_contract_and_hmac_v1(): void
    {
        config()->set('services.andy_core', [
            'url' => 'https://example.test',
            'connection_key' => 'example.test',
            'key_id' => 'erp-key',
            'shared_secret' => 'test-shared-secret',
            'verify_tls' => true,
            'timeout' => 15,
            'connect_timeout' => 5,
        ]);

        [$article, $layoutId, $previewHash] = $this->layout();

        Http::fake([
            'https://example.test/wp-json/andy-core/v1/erp/content/publish' => Http::response([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req_draft_1',
                'connection_key' => 'example.test',
                'data' => [
                    'resource_contract_version' => 'content-publish-v1',
                    'provider' => 'wordpress',
                    'post_id' => 77,
                    'external_id' => '77',
                    'status' => 'draft',
                    'url' => 'https://example.test/blog/irrigation-guide/',
                    'created' => true,
                    'layout_snapshot_id' => $layoutId,
                    'preview_hash' => $previewHash,
                    'canonical_path_expected' => '/blog/irrigation-guide/',
                    'canonical_path_match' => true,
                    'indexing' => 'noindex',
                ],
            ], 200),
        ]);

        $result = app(AndyCoreContentPublishingAdapter::class)
            ->draft($article->id, $layoutId, 'content-draft:test-key');

        self::assertSame('andy_core', $result['provider']);
        self::assertSame('draft', $result['status']);
        self::assertSame('77', $result['external_id']);
        self::assertSame($layoutId, $result['layout_snapshot_id']);
        self::assertSame($previewHash, $result['preview_hash']);
        self::assertSame('noindex', $result['indexing']);

        Http::assertSent(function (Request $request) use ($article, $layoutId, $previewHash): bool {
            $body = $request->body();
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $timestamp = $request->header('X-YBY-Timestamp')[0] ?? '';
            $nonce = $request->header('X-YBY-Nonce')[0] ?? '';
            $signature = $request->header('X-YBY-Signature')[0] ?? '';
            $canonical = implode("\n", [
                'POST',
                '/wp-json/andy-core/v1/erp/content/publish',
                $timestamp,
                $nonce,
                'example.test',
                'content-draft:test-key',
                hash('sha256', $body),
            ]);

            return $request->url() === 'https://example.test/wp-json/andy-core/v1/erp/content/publish'
                && $request->header('X-YBY-Key-Id')[0] === 'erp-key'
                && $request->header('X-YBY-Signature-Version')[0] === 'v1'
                && $request->header('X-YBY-Idempotency-Key')[0] === 'content-draft:test-key'
                && hash_equals(hash_hmac('sha256', $canonical, 'test-shared-secret'), $signature)
                && $payload === [
                    'erp_article_id' => $article->id,
                    'layout_snapshot_id' => $layoutId,
                    'preview_hash' => $previewHash,
                    'target_site' => 'example.test',
                    'content_type' => 'blog',
                    'title' => 'Irrigation Guide',
                    'html' => '<h2>Selection</h2>'."\n".'<p>Choose from verified requirements.</p>',
                    'canonical_path' => '/blog/irrigation-guide/',
                    'seo' => [
                        'primary_keyword' => 'irrigation guide',
                        'search_intent' => 'informational',
                        'indexing' => 'noindex',
                    ],
                    'requested_status' => 'draft',
                ];
        });
    }

    public function test_publish_rejects_mismatched_provider_identity(): void
    {
        config()->set('services.andy_core', [
            'url' => 'https://example.test',
            'connection_key' => 'example.test',
            'key_id' => 'erp-key',
            'shared_secret' => 'test-shared-secret',
            'verify_tls' => true,
            'timeout' => 15,
            'connect_timeout' => 5,
        ]);

        [$article, $layoutId, $previewHash] = $this->layout();

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req_publish_1',
                'connection_key' => 'example.test',
                'data' => [
                    'resource_contract_version' => 'content-publish-v1',
                    'provider' => 'wordpress',
                    'post_id' => 77,
                    'external_id' => '77',
                    'status' => 'publish',
                    'url' => 'https://example.test/blog/irrigation-guide/',
                    'created' => false,
                    'layout_snapshot_id' => $layoutId,
                    'preview_hash' => str_repeat('f', 64),
                    'canonical_path_expected' => '/blog/irrigation-guide/',
                    'canonical_path_match' => true,
                    'indexing' => 'index',
                ],
            ], 200),
        ]);

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('Andy Core response identity does not match');

        app(AndyCoreContentPublishingAdapter::class)
            ->publish($article->id, $layoutId, 'content-publish:test-key');

        self::assertSame($previewHash, 'unreachable');
    }

    public function test_publish_rejects_provider_indexing_readback_mismatch(): void
    {
        config()->set('services.andy_core', [
            'url' => 'https://example.test',
            'connection_key' => 'example.test',
            'key_id' => 'erp-key',
            'shared_secret' => 'test-shared-secret',
            'verify_tls' => true,
            'timeout' => 15,
            'connect_timeout' => 5,
        ]);

        [$article, $layoutId, $previewHash] = $this->layout();
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req_publish_indexing_mismatch',
                'connection_key' => 'example.test',
                'data' => [
                    'resource_contract_version' => 'content-publish-v1',
                    'provider' => 'wordpress',
                    'post_id' => 77,
                    'external_id' => '77',
                    'status' => 'publish',
                    'url' => 'https://example.test/blog/irrigation-guide/',
                    'created' => false,
                    'layout_snapshot_id' => $layoutId,
                    'preview_hash' => $previewHash,
                    'canonical_path_expected' => '/blog/irrigation-guide/',
                    'canonical_path_match' => true,
                    'indexing' => 'noindex',
                ],
            ], 200),
        ]);

        try {
            app(AndyCoreContentPublishingAdapter::class)
                ->publish($article->id, $layoutId, 'content-publish:indexing-mismatch');
            self::fail('Expected provider indexing mismatch to fail closed.');
        } catch (PublicationException $exception) {
            self::assertSame('andy_core_response_mismatch', $exception->reason);
        }

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return ($payload['requested_status'] ?? null) === 'publish'
                && ($payload['seo']['indexing'] ?? null) === 'index';
        });
    }

    /** @return array{ContentArticle,int,string} */
    private function layout(): array
    {
        $article = ContentArticle::query()->create([
            'source_identity_hash' => hash('sha256', 'andy-content-adapter'),
            'source_system' => 'test',
            'source_ref' => 'andy-content-adapter',
            'project_key' => 'yby-irrigation',
            'brand_key' => 'yby-irrigation',
            'target_site' => 'example.test',
            'language' => 'en',
            'content_type' => 'blog',
            'primary_keyword' => 'irrigation guide',
            'search_intent' => 'informational',
            'title_direction' => 'Irrigation Guide',
            'canonical_path' => '/blog/irrigation-guide/',
            'authoring_mode' => 'human',
            'workflow_status' => 'layout_ready',
        ]);

        $preview = [
            'schema' => 'content-preview-v1',
            'source' => [
                'type' => 'editorial',
                'snapshot_id' => 9,
                'version' => 1,
                'hash' => str_repeat('a', 64),
            ],
            'target_site' => 'example.test',
            'content_type' => 'blog',
            'title' => 'Irrigation Guide',
            'canonical_path' => '/blog/irrigation-guide/',
            'seo' => [
                'primary_keyword' => 'irrigation guide',
                'search_intent' => 'informational',
            ],
            'html' => '<h2>Selection</h2>'."\n".'<p>Choose from verified requirements.</p>',
            'verification_required' => [],
        ];
        $json = json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $previewHash = hash('sha256', $json);
        $layoutId = DB::table('content_snapshots')->insertGetId([
            'article_id' => $article->id,
            'type' => 'layout',
            'version' => 1,
            'normalized_json' => $json,
            'content_hash' => $previewHash,
            'created_by_type' => 'system',
            'created_by_id' => '1',
            'created_at' => now(),
        ]);

        return [$article, $layoutId, $previewHash];
    }
}
