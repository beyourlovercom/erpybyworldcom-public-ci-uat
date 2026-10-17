<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\Contracts\DraftPublishingAdapter;
use App\Modules\Content\Application\Contracts\PublishingAdapter;
use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Support\Facades\DB;

final class PreviewOnlyPublishingAdapter implements DraftPublishingAdapter, PublishingAdapter
{
    public function preview(int $articleId, int $snapshotId): array
    {
        $article = ContentArticle::query()->find($articleId);
        $snapshot = DB::table('content_snapshots')->where('id', $snapshotId)->first();

        if (! $article || ! $snapshot || (int) $snapshot->article_id !== $articleId || (string) $snapshot->type !== 'editorial') {
            throw new PublicationException('editorial_snapshot_invalid', 'A valid editorial snapshot is required for preview.');
        }

        $editorial = json_decode((string) $snapshot->normalized_json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($editorial) || ($editorial['schema'] ?? null) !== 'content-editorial-v1') {
            throw new PublicationException('editorial_snapshot_invalid', 'Editorial snapshot schema is invalid.');
        }

        $title = trim((string) ($editorial['title'] ?? $article->title_direction));
        $blocks = is_array($editorial['blocks'] ?? null) ? $editorial['blocks'] : [];
        $verification = is_array($editorial['verification_required'] ?? null)
            ? array_values($editorial['verification_required'])
            : [];

        return [
            'schema' => 'content-preview-v1',
            'source' => [
                'type' => 'editorial',
                'snapshot_id' => (int) $snapshot->id,
                'version' => (int) $snapshot->version,
                'hash' => (string) $snapshot->content_hash,
            ],
            'target_site' => (string) $article->target_site,
            'content_type' => (string) $article->content_type,
            'title' => $title,
            'canonical_path' => $article->canonical_path ? (string) $article->canonical_path : null,
            'seo' => [
                'primary_keyword' => $article->primary_keyword ? (string) $article->primary_keyword : null,
                'search_intent' => $article->search_intent ? (string) $article->search_intent : null,
            ],
            'html' => $this->render($blocks),
            'verification_required' => $verification,
        ];
    }

    public function draft(int $articleId, int $snapshotId, string $idempotencyKey): array
    {
        throw new PublicationException(
            'live_draft_unavailable',
            'Live draft publishing is unavailable until the Andy Core content publishing adapter is configured.',
        );
    }

    public function publish(int $articleId, int $snapshotId, string $idempotencyKey): array
    {
        throw new PublicationException(
            'live_publish_unavailable',
            'Live publishing is fail-closed until the Andy Core content publishing endpoint contract is available.',
        );
    }

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks): string
    {
        $html = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            $text = htmlspecialchars((string) ($block['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($type === 'heading') {
                $level = min(6, max(2, (int) ($block['level'] ?? 2)));
                $html[] = "<h{$level}>{$text}</h{$level}>";

                continue;
            }

            if ($type === 'paragraph') {
                if ($text !== '') {
                    $html[] = "<p>{$text}</p>";
                }

                continue;
            }

            if (in_array($type, ['bulleted_list', 'numbered_list'], true)) {
                $tag = $type === 'numbered_list' ? 'ol' : 'ul';
                $items = [];
                foreach ((array) ($block['items'] ?? []) as $item) {
                    $items[] = '<li>'.htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>';
                }
                if ($items !== []) {
                    $html[] = "<{$tag}>".implode('', $items)."</{$tag}>";
                }
            }
        }

        return implode("\n", $html);
    }
}
