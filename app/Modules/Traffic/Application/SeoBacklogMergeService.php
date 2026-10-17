<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Support\Facades\DB;

final class SeoBacklogMergeService
{
    /** @return array<string,mixed> */
    public function merge(
        string $projectKey,
        int $sourceBacklogId,
        int $targetBacklogId,
        string $mode = 'dry_run'
    ): array {
        $summary = [
            'mode' => $mode,
            'project_key' => $projectKey,
            'source_backlog_id' => $sourceBacklogId,
            'target_backlog_id' => $targetBacklogId,
            'seen' => 0,
            'applied' => 0,
            'target' => null,
            'source_before' => null,
            'source_after' => null,
            'blocked' => [],
        ];

        $project = BusinessProject::query()
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->first();

        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }
        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }
        if ($sourceBacklogId < 1 || $targetBacklogId < 1 || $sourceBacklogId === $targetBacklogId) {
            return $this->blocked($summary, 'invalid_backlog_ids');
        }

        $source = DB::table('seo_content_backlog')
            ->where('business_project_id', $project->id)
            ->where('id', $sourceBacklogId)
            ->first();
        $target = DB::table('seo_content_backlog')
            ->where('business_project_id', $project->id)
            ->where('id', $targetBacklogId)
            ->first();

        if (! $source) {
            return $this->blocked($summary, 'source_backlog_not_found');
        }
        if (! $target) {
            return $this->blocked($summary, 'target_backlog_not_found');
        }
        if ((string) $source->status !== 'planned') {
            return $this->blocked($summary, 'source_backlog_not_planned');
        }
        if (! in_array((string) $source->content_type, ['blog', 'commercial_page', 'comparison_blog', 'content_review'], true)) {
            return $this->blocked($summary, 'source_backlog_not_mergeable');
        }
        if (! $target->target_url) {
            return $this->blocked($summary, 'target_backlog_missing_url');
        }
        if ((string) $target->content_type !== 'optimize_existing') {
            return $this->blocked($summary, 'target_backlog_not_existing_canonical');
        }

        $targetWorkOrder = DB::table('seo_optimization_work_orders')
            ->where('business_project_id', $project->id)
            ->where(function ($q) use ($targetBacklogId, $target): void {
                $q->where('seo_content_backlog_id', $targetBacklogId)
                    ->orWhere('target_url_hash', hash('sha256', (string) $target->target_url));
            })
            ->orderByDesc('id')
            ->first();

        $publication = $targetWorkOrder
            ? DB::table('seo_optimization_publications')
                ->where('seo_optimization_work_order_id', $targetWorkOrder->id)
                ->first()
            : null;

        $summary['seen'] = 1;
        $summary['source_before'] = $this->backlogView($source);
        $summary['target'] = [
            'backlog_id' => (int) $target->id,
            'content_type' => (string) $target->content_type,
            'status' => (string) $target->status,
            'title' => (string) $target->title,
            'target_url' => (string) $target->target_url,
            'work_order_id' => $targetWorkOrder?->id === null ? null : (int) $targetWorkOrder->id,
            'work_order_status' => $targetWorkOrder?->workflow_status,
            'publication_id' => $publication?->id === null ? null : (int) $publication->id,
            'publication_status' => $publication?->status,
            'retest_after' => $publication?->retest_after,
        ];

        $after = [
            'id' => (int) $source->id,
            'content_type' => 'cluster_member',
            'status' => 'retired',
            'title' => (string) $source->title,
            'target_url' => (string) $target->target_url,
            'rationale' => 'Merged into backlog #'.$targetBacklogId.' for target_url='.(string) $target->target_url.'; semantic intent overlap with existing canonical; defer content changes until target publication retest.',
        ];
        $summary['source_after'] = $after;

        if ($mode === 'apply') {
            DB::transaction(function () use ($project, $sourceBacklogId, $targetBacklogId, $target, $after, &$summary): void {
                $locked = DB::table('seo_content_backlog')
                    ->where('business_project_id', $project->id)
                    ->where('id', $sourceBacklogId)
                    ->lockForUpdate()
                    ->first();
                if (! $locked || (string) $locked->status !== 'planned') {
                    throw new \RuntimeException('source_backlog_state_changed');
                }

                $targetStillExists = DB::table('seo_content_backlog')
                    ->where('business_project_id', $project->id)
                    ->where('id', $targetBacklogId)
                    ->where('content_type', 'optimize_existing')
                    ->where('target_url', (string) $target->target_url)
                    ->exists();
                if (! $targetStillExists) {
                    throw new \RuntimeException('target_backlog_state_changed');
                }

                $updated = DB::table('seo_content_backlog')
                    ->where('id', $sourceBacklogId)
                    ->update([
                        'content_type' => $after['content_type'],
                        'status' => $after['status'],
                        'target_url' => $after['target_url'],
                        'rationale' => $after['rationale'],
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('source_backlog_update_failed');
                }
                $summary['applied'] = 1;
            });
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    private function backlogView(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'content_type' => (string) $row->content_type,
            'status' => (string) $row->status,
            'title' => (string) $row->title,
            'target_url' => $row->target_url,
            'rationale' => (string) $row->rationale,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];

        return $summary;
    }
}
