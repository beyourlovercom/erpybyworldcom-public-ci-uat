<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\WriterPoolRepository;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentWriterPoolRepository implements WriterPoolRepository
{
    public function claimableFor(int $userId, bool $allScopes = false): array
    {
        $now = now();

        return $this->eligibleQuery($userId, $allScopes, $now)
            ->orderBy('id')
            ->get()
            ->map(fn (ContentArticle $article): array => $this->payload($article))
            ->all();
    }

    public function mineFor(int $userId): array
    {
        return ContentArticle::query()
            ->where('writer_id', $userId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ContentArticle $article): array => $this->payload($article))
            ->all();
    }

    public function claimNext(int $userId, bool $allScopes = false, int $claimHours = 24): ?int
    {
        return DB::transaction(function () use ($userId, $allScopes, $claimHours): ?int {
            $now = now();
            $article = $this->eligibleQuery($userId, $allScopes, $now)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $article) {
                return null;
            }

            if ($article->workflow_status === ContentWorkflowStatus::WriterClaimed) {
                $this->event(
                    $article->id,
                    'claim_expired',
                    null,
                    ContentWorkflowStatus::WriterClaimed,
                    ContentWorkflowStatus::WaitingForWriter,
                    ['previous_writer_id' => $article->writer_id],
                );
            }

            $from = $article->workflow_status;
            $article->forceFill([
                'writer_id' => $userId,
                'workflow_status' => ContentWorkflowStatus::WriterClaimed,
                'claimed_at' => $now,
                'claim_expires_at' => $now->copy()->addHours($claimHours),
            ])->save();

            $this->event(
                $article->id,
                'writer_claimed',
                $userId,
                $from,
                ContentWorkflowStatus::WriterClaimed,
                ['claim_expires_at' => $article->claim_expires_at?->toIso8601String()],
            );

            return (int) $article->id;
        }, 3);
    }

    public function startWriting(int $articleId, int $userId): void
    {
        DB::transaction(function () use ($articleId, $userId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || (int) $article->writer_id !== $userId) {
                throw new DomainException('content_article_not_owned');
            }
            if ($article->workflow_status !== ContentWorkflowStatus::WriterClaimed) {
                throw new DomainException('content_article_not_claimed');
            }
            if ($article->claim_expires_at && $article->claim_expires_at->isPast()) {
                throw new DomainException('content_claim_expired');
            }

            $article->forceFill([
                'workflow_status' => ContentWorkflowStatus::Writing,
                'claim_expires_at' => null,
            ])->save();

            $this->event(
                $article->id,
                'writing_started',
                $userId,
                ContentWorkflowStatus::WriterClaimed,
                ContentWorkflowStatus::Writing,
            );
        }, 3);
    }

    public function release(int $articleId, int $userId): void
    {
        DB::transaction(function () use ($articleId, $userId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || (int) $article->writer_id !== $userId) {
                throw new DomainException('content_article_not_owned');
            }
            if (! in_array($article->workflow_status, [
                ContentWorkflowStatus::WriterClaimed,
                ContentWorkflowStatus::Writing,
                ContentWorkflowStatus::ReturnedForRevision,
            ], true)) {
                throw new DomainException('content_article_not_releasable');
            }

            $from = $article->workflow_status;
            $article->forceFill([
                'writer_id' => null,
                'workflow_status' => ContentWorkflowStatus::WaitingForWriter,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ])->save();

            $this->event(
                $article->id,
                'writer_released',
                $userId,
                $from,
                ContentWorkflowStatus::WaitingForWriter,
            );
        }, 3);
    }

    private function eligibleQuery(int $userId, bool $allScopes, $now): Builder
    {
        $query = ContentArticle::query()
            ->where(function (Builder $query) use ($now): void {
                $query->where('workflow_status', ContentWorkflowStatus::WaitingForWriter->value)
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->where('workflow_status', ContentWorkflowStatus::WriterClaimed->value)
                            ->whereNotNull('claim_expires_at')
                            ->where('claim_expires_at', '<=', $now);
                    });
            });

        if (! $allScopes) {
            $query->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('content_writer_scopes as scope')
                    ->whereColumn('scope.project_key', 'content_articles.project_key')
                    ->whereColumn('scope.language', 'content_articles.language')
                    ->where('scope.user_id', $userId)
                    ->where('scope.active', true);
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function payload(ContentArticle $article): array
    {
        return [
            'id' => (int) $article->id,
            'project_key' => $article->project_key,
            'brand_key' => $article->brand_key,
            'target_site' => $article->target_site,
            'language' => $article->language,
            'content_type' => $article->content_type,
            'title_direction' => $article->title_direction,
            'primary_keyword' => $article->primary_keyword,
            'search_intent' => $article->search_intent,
            'topic_cluster' => $article->topic_cluster,
            'recommended_word_count' => $article->recommended_word_count,
            'workflow_status' => $article->workflow_status->value,
            'writer_id' => $article->writer_id ? (int) $article->writer_id : null,
            'claimed_at' => $article->claimed_at?->toIso8601String(),
            'claim_expires_at' => $article->claim_expires_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function event(
        int $articleId,
        string $eventType,
        ?int $actorId,
        ?ContentWorkflowStatus $from,
        ?ContentWorkflowStatus $to,
        ?array $metadata = null,
    ): void {
        DB::table('content_article_events')->insert([
            'article_id' => $articleId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    }
}
