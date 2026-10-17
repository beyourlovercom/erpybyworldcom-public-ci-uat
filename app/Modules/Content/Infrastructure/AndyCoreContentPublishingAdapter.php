<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\Contracts\DraftPublishingAdapter;
use App\Modules\Content\Application\Contracts\PublishingAdapter;
use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Kol\Infrastructure\AffiliateWp\HmacV1Signer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class AndyCoreContentPublishingAdapter implements DraftPublishingAdapter, PublishingAdapter
{
    private const string Namespace = '/wp-json/andy-core/v1/erp';

    private const string ResourceContractVersion = 'content-publish-v1';

    public function __construct(
        private readonly PreviewOnlyPublishingAdapter $previewAdapter,
    ) {}

    public function preview(int $articleId, int $snapshotId): array
    {
        return $this->previewAdapter->preview($articleId, $snapshotId);
    }

    public function draft(int $articleId, int $snapshotId, string $idempotencyKey): array
    {
        return $this->mutate($articleId, $snapshotId, $idempotencyKey, 'draft');
    }

    public function publish(int $articleId, int $snapshotId, string $idempotencyKey): array
    {
        return $this->mutate($articleId, $snapshotId, $idempotencyKey, 'publish');
    }

    /** @return array<string,mixed> */
    private function mutate(int $articleId, int $snapshotId, string $idempotencyKey, string $status): array
    {
        if (! $this->configured()) {
            throw new PublicationException(
                'andy_core_not_configured',
                'Andy Core Content publishing connector is not configured.',
            );
        }

        $payload = $this->payload($articleId, $snapshotId, $status);
        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $path = self::Namespace.'/content/publish';
        $timestamp = (string) CarbonImmutable::now('UTC')->getTimestamp();
        $nonce = Str::uuid()->toString();
        $headers = HmacV1Signer::headers(
            method: 'POST',
            pathWithQuery: $path,
            connectionKey: $this->connectionKey(),
            keyId: $this->keyId(),
            sharedSecret: $this->secret(),
            body: $body,
            idempotencyKey: $idempotencyKey,
            timestamp: $timestamp,
            nonce: $nonce,
        );
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = 'YBY-ERP-Content-Andy-Core-Connector/1.0';

        $request = Http::withHeaders($headers)
            ->timeout(max(1, (int) config('services.andy_core.timeout', 15)))
            ->connectTimeout(max(1, (int) config('services.andy_core.connect_timeout', 5)))
            ->withoutRedirecting()
            ->withOptions(['verify' => $this->shouldVerifyTls()]);

        try {
            $response = $request->withBody($body, 'application/json')->post($this->baseUrl().$path);
        } catch (ConnectionException) {
            throw new PublicationException(
                'andy_core_unavailable',
                'Andy Core Content publishing connector is unavailable.',
            );
        }

        $raw = $response->json();
        if (! is_array($raw)) {
            throw new PublicationException('andy_core_invalid_response', 'Andy Core returned a non-JSON response.');
        }

        if (! $response->successful()) {
            $code = is_string($raw['code'] ?? null) ? strtolower((string) $raw['code']) : 'andy_core_http_'.$response->status();
            throw new PublicationException($code, 'Andy Core rejected the Content publishing request.');
        }

        return $this->validatedSuccess($raw, $payload, $status);
    }

    /** @return array<string,mixed> */
    private function payload(int $articleId, int $snapshotId, string $status): array
    {
        $article = ContentArticle::query()->find($articleId);
        $snapshot = DB::table('content_snapshots')
            ->where('id', $snapshotId)
            ->where('article_id', $articleId)
            ->where('type', 'layout')
            ->first();

        if (! $article || ! $snapshot) {
            throw new PublicationException(
                'layout_snapshot_invalid',
                'A valid layout snapshot is required for Andy Core publishing.',
            );
        }

        $preview = json_decode((string) $snapshot->normalized_json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($preview) || ($preview['schema'] ?? null) !== 'content-preview-v1') {
            throw new PublicationException('layout_snapshot_invalid', 'Layout snapshot schema is invalid.');
        }

        return [
            'erp_article_id' => (int) $article->id,
            'layout_snapshot_id' => (int) $snapshot->id,
            'preview_hash' => (string) $snapshot->content_hash,
            'target_site' => (string) $article->target_site,
            'content_type' => (string) $article->content_type,
            'title' => trim((string) ($preview['title'] ?? $article->title_direction)),
            'html' => (string) ($preview['html'] ?? ''),
            'canonical_path' => $article->canonical_path ? (string) $article->canonical_path : null,
            'seo' => [
                'primary_keyword' => $article->primary_keyword ? (string) $article->primary_keyword : null,
                'search_intent' => $article->search_intent ? (string) $article->search_intent : null,
                'indexing' => $status === 'draft' ? 'noindex' : 'index',
            ],
            'requested_status' => $status,
        ];
    }

    /** @param array<string,mixed> $raw @param array<string,mixed> $payload @return array<string,mixed> */
    private function validatedSuccess(array $raw, array $payload, string $status): array
    {
        if (($raw['ok'] ?? null) !== true
            || ($raw['contract_version'] ?? null) !== '1'
            || ! is_string($raw['request_id'] ?? null)
            || trim((string) $raw['request_id']) === ''
            || ($raw['connection_key'] ?? null) !== $this->connectionKey()
            || ! is_array($raw['data'] ?? null)) {
            throw new PublicationException(
                'andy_core_invalid_response',
                'Andy Core returned an invalid response envelope.',
            );
        }

        $data = $raw['data'];
        $expectedIndexing = $status === 'draft' ? 'noindex' : 'index';
        if (($data['resource_contract_version'] ?? null) !== self::ResourceContractVersion
            || ($data['provider'] ?? null) !== 'wordpress'
            || (string) ($data['status'] ?? '') !== $status
            || (int) ($data['layout_snapshot_id'] ?? 0) !== (int) $payload['layout_snapshot_id']
            || ! is_string($data['preview_hash'] ?? null)
            || ! hash_equals((string) $payload['preview_hash'], (string) $data['preview_hash'])
            || (string) ($data['indexing'] ?? '') !== $expectedIndexing
            || trim((string) ($data['external_id'] ?? '')) === '') {
            throw new PublicationException(
                'andy_core_response_mismatch',
                'Andy Core response identity does not match the requested Content layout.',
            );
        }

        return [
            'provider' => 'andy_core',
            'request_id' => (string) $raw['request_id'],
            'external_id' => (string) $data['external_id'],
            'post_id' => isset($data['post_id']) ? (int) $data['post_id'] : null,
            'status' => (string) $data['status'],
            'url' => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            'created' => (bool) ($data['created'] ?? false),
            'layout_snapshot_id' => (int) $data['layout_snapshot_id'],
            'preview_hash' => (string) $data['preview_hash'],
            'canonical_path_expected' => $data['canonical_path_expected'] ?? null,
            'canonical_path_match' => $data['canonical_path_match'] ?? null,
            'indexing' => $expectedIndexing,
        ];
    }

    private function configured(): bool
    {
        return $this->baseUrl() !== ''
            && $this->connectionKey() !== ''
            && $this->keyId() !== ''
            && $this->secret() !== '';
    }

    private function baseUrl(): string
    {
        $raw = rtrim(trim((string) config('services.andy_core.url')), '/');
        if ($raw === '') {
            return '';
        }

        $parsed = parse_url($raw);
        if ($parsed === false
            || empty($parsed['host'])
            || ! in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            || ! empty($parsed['user'])
            || ! empty($parsed['pass'])
            || ! empty($parsed['query'])
            || ! empty($parsed['fragment'])
            || (! empty($parsed['path']) && $parsed['path'] !== '/')
            || (! in_array(config('app.env', 'production'), ['local', 'testing'], true)
                && ($parsed['scheme'] ?? '') !== 'https')) {
            return '';
        }

        return $parsed['scheme'].'://'.$parsed['host'].(! empty($parsed['port']) ? ':'.$parsed['port'] : '');
    }

    private function connectionKey(): string
    {
        return trim((string) config('services.andy_core.connection_key', ''));
    }

    private function keyId(): string
    {
        return trim((string) config('services.andy_core.key_id', ''));
    }

    private function secret(): string
    {
        return (string) config('services.andy_core.shared_secret', '');
    }

    private function shouldVerifyTls(): bool
    {
        return ! in_array(config('app.env', 'production'), ['local', 'testing'], true)
            || (bool) config('services.andy_core.verify_tls', true);
    }
}
