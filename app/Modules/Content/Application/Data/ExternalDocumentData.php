<?php

namespace App\Modules\Content\Application\Data;

final readonly class ExternalDocumentData
{
    /** @param array<string, mixed> $normalizedJson */
    public function __construct(
        public string $provider,
        public string $documentId,
        public string $documentUrl,
        public string $title,
        public ?string $modifiedTime,
        public ?string $revisionId,
        public array $normalizedJson,
    ) {
    }

    public function contentHash(): string
    {
        $encoded = json_encode(
            $this->normalizedJson,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded);
    }
}
