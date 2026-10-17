<?php

namespace App\Modules\Content\Infrastructure\GoogleDocs;

use App\Modules\Content\Application\Contracts\DocumentReadClient;
use App\Modules\Content\Application\Data\ExternalDocumentData;
use App\Modules\Content\Application\Exceptions\DocumentImportException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpGoogleDocsReadClient implements DocumentReadClient
{
    private ?string $accessToken = null;

    public function __construct(private readonly GoogleDocsNormalizer $normalizer)
    {
    }

    public function fetch(string $documentUrl): ExternalDocumentData
    {
        $documentId = $this->documentId($documentUrl);
        $config = $this->credentials();

        $documentResponse = $this->client()->get(
            rtrim($config['docs_base_url'], '/').'/documents/'.rawurlencode($documentId),
        );
        if (! $documentResponse->successful()) {
            throw new DocumentImportException(
                'provider_document_read_failed',
                'Google Docs read request failed with HTTP '.$documentResponse->status().'.',
            );
        }

        $document = $documentResponse->json();
        if (! is_array($document)) {
            throw new DocumentImportException('provider_invalid_response', 'Google Docs returned invalid JSON.');
        }

        $metadataResponse = $this->client()->get(
            rtrim($config['drive_base_url'], '/').'/files/'.rawurlencode($documentId),
            [
                'fields' => 'id,name,mimeType,modifiedTime,version,webViewLink',
                'supportsAllDrives' => 'true',
            ],
        );
        if (! $metadataResponse->successful()) {
            throw new DocumentImportException(
                'provider_metadata_read_failed',
                'Google Drive metadata request failed with HTTP '.$metadataResponse->status().'.',
            );
        }

        $metadata = $metadataResponse->json();
        if (! is_array($metadata)) {
            throw new DocumentImportException('provider_invalid_response', 'Google Drive returned invalid JSON.');
        }

        $mimeType = trim((string) ($metadata['mimeType'] ?? ''));
        if ($mimeType !== 'application/vnd.google-apps.document') {
            throw new DocumentImportException('not_google_document', 'Submitted file is not a Google Docs document.');
        }

        $revisionId = trim((string) ($document['revisionId'] ?? ''));
        if ($revisionId === '') {
            $version = trim((string) ($metadata['version'] ?? ''));
            $revisionId = $version === '' ? null : 'drive-version:'.$version;
        }

        $canonicalUrl = trim((string) ($metadata['webViewLink'] ?? ''));
        if ($canonicalUrl === '') {
            $canonicalUrl = 'https://docs.google.com/document/d/'.$documentId.'/edit';
        }

        return new ExternalDocumentData(
            provider: 'google_docs',
            documentId: $documentId,
            documentUrl: $canonicalUrl,
            title: trim((string) ($document['title'] ?? $metadata['name'] ?? '')),
            modifiedTime: $this->nullableString($metadata['modifiedTime'] ?? null),
            revisionId: $revisionId,
            normalizedJson: $this->normalizer->normalize($document),
        );
    }

    private function client(): PendingRequest
    {
        $config = $this->credentials();

        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout($config['timeout']);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $config = $this->credentials();
        $response = Http::asForm()->timeout($config['timeout'])->post(
            $config['oauth_url'],
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        );

        if (! $response->successful()) {
            throw new DocumentImportException(
                'authentication_failed',
                'Google Docs OAuth token refresh failed with HTTP '.$response->status().'.',
            );
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new DocumentImportException('authentication_failed', 'Google OAuth response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    private function documentId(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($host, ['docs.google.com', 'www.docs.google.com'], true)
            || preg_match('#^/document/d/([A-Za-z0-9_-]{10,})#D', $path, $matches) !== 1) {
            throw new DocumentImportException('invalid_document_url', 'A valid Google Docs document URL is required.');
        }

        return $matches[1];
    }

    /** @return array{client_id:string,client_secret:string,refresh_token:string,docs_base_url:string,drive_base_url:string,oauth_url:string,timeout:int} */
    private function credentials(): array
    {
        $config = [
            'client_id' => trim((string) config('services.google_docs.client_id', '')),
            'client_secret' => trim((string) config('services.google_docs.client_secret', '')),
            'refresh_token' => trim((string) config('services.google_docs.refresh_token', '')),
            'docs_base_url' => rtrim((string) config('services.google_docs.docs_base_url', 'https://docs.googleapis.com/v1'), '/'),
            'drive_base_url' => rtrim((string) config('services.google_docs.drive_base_url', 'https://www.googleapis.com/drive/v3'), '/'),
            'oauth_url' => (string) config('services.google_docs.oauth_url', 'https://oauth2.googleapis.com/token'),
            'timeout' => max(1, (int) config('services.google_docs.timeout', 20)),
        ];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $required) {
            if ($config[$required] === '') {
                throw new DocumentImportException('credentials_missing', 'Google Docs OAuth credentials are not configured.');
            }
        }

        return $config;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
