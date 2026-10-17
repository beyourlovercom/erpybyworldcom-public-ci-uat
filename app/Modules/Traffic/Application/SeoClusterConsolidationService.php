<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Support\Facades\DB;

final class SeoClusterConsolidationService
{
    /** @return array<string,mixed> */
    public function consolidate(string $projectKey, string $mode = 'dry_run'): array
    {
        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        $summary = ['mode'=>$mode,'project_key'=>$projectKey,'optimize_rows'=>0,'mapped_rows'=>0,'unmapped_rows'=>0,'optimize_clusters'=>0,'merged_rows'=>0,'new_content_seen'=>0,'new_content_active'=>0,'competitor_terms'=>0,'blocked'=>[]];
        if (! $project) return $this->blocked($summary, 'project_not_found');
        if (! in_array($mode, ['dry_run','apply'], true)) return $this->blocked($summary, 'invalid_mode');

        $allOptimize = DB::table('seo_content_backlog')->where('business_project_id',$project->id)->where('content_type','optimize_existing');
        $summary['optimize_rows'] = (clone $allOptimize)->count();
        $summary['unmapped_rows'] = (clone $allOptimize)->whereNull('target_url')->count();
        $groups = (clone $allOptimize)->whereNotNull('target_url')->get()->groupBy('target_url');
        $summary['mapped_rows'] = $groups->sum(fn($rows) => $rows->count());
        $summary['optimize_clusters'] = $groups->count();
        $summary['merged_rows'] = $summary['mapped_rows'] - $summary['optimize_clusters'];

        if ($mode === 'apply') DB::transaction(function () use ($project, $groups, &$summary): void {
            foreach ($groups as $url => $rows) $this->consolidateUrlCluster($project->id, (string)$url, $rows);
            DB::table('seo_content_backlog')->where('business_project_id',$project->id)->where('content_type','optimize_existing')->whereNull('target_url')->update(['content_type'=>'content_review','status'=>'planned','rationale'=>'No live GSC target_url; requires target-page review before optimization.','updated_at'=>now()]);
            $this->auditNewContent($project->id, $summary);
        });
        else $this->previewNewContent($project->id, $summary);
        return $summary;
    }
    private function consolidateUrlCluster(int $projectId, string $url, $rows): void
    {
        $ids = $rows->pluck('seo_keyword_id')->filter()->all();
        $metrics = DB::table('seo_gsc_daily_metrics')->whereIn('seo_keyword_id',$ids)
            ->select('seo_keyword_id', DB::raw('SUM(impressions) impressions'))->groupBy('seo_keyword_id')
            ->pluck('impressions','seo_keyword_id');
        $primary = $rows->sortByDesc(fn($row) => (int)($metrics[$row->seo_keyword_id] ?? 0))->first();
        $keywords = DB::table('seo_keywords')->whereIn('id',$ids)->orderBy('keyword')->pluck('keyword')->all();
        DB::table('seo_content_backlog')->where('id',$primary->id)->update([
            'title'=>(string)DB::table('seo_keywords')->where('id',$primary->seo_keyword_id)->value('keyword'),
            'status'=>'optimize',
            'rationale'=>'Cluster task; target_url='.$url.'; keywords='.implode(' | ',$keywords),
            'updated_at'=>now(),
        ]);
        DB::table('seo_content_backlog')->whereIn('id',$rows->pluck('id')->reject(fn($id)=>$id===$primary->id)->all())->update([
            'content_type'=>'cluster_member','status'=>'retired',
            'rationale'=>'Merged into backlog #'.$primary->id.' for target_url='.$url,
            'updated_at'=>now(),
        ]);
    }

    /** @param array<string,mixed> $summary */
    private function previewNewContent(int $projectId, array &$summary): void
    {
        $rows = $this->newContentRows($projectId);
        $summary['new_content_seen'] = $rows->count();
        $summary['competitor_terms'] = $rows->where('is_competitor_term',1)->count();
        $summary['new_content_active'] = $this->plannedNewTaskCount($rows);
    }
    /** @param array<string,mixed> $summary */
    private function auditNewContent(int $projectId, array &$summary): void
    {
        $rows = $this->newContentRows($projectId);
        $summary['new_content_seen'] = $rows->count();
        $competitors = $rows->where('is_competitor_term',1)->values();
        $summary['competitor_terms'] = $competitors->count();

        if ($competitors->isNotEmpty()) {
            $primary = $competitors->first();
            $brands = $competitors->pluck('keyword')->all();
            DB::table('seo_content_backlog')->where('id',$primary->id)->update([
                'content_type'=>'comparison_blog','status'=>'planned',
                'title'=>'Drip irrigation kit brands and price comparison',
                'rationale'=>'Competitor-intent cluster; cover '.implode(' | ',$brands).' in one neutral comparison article; do not create standalone competitor brand pages.',
                'updated_at'=>now(),
            ]);
            DB::table('seo_content_backlog')->whereIn('id',$competitors->pluck('id')->slice(1)->all())->update([
                'content_type'=>'cluster_member','status'=>'retired',
                'rationale'=>'Merged into competitor comparison backlog #'.$primary->id,
                'updated_at'=>now(),
            ]);
        }

        $pipeRows = $rows->filter(fn($row) => !$row->is_competitor_term && str_contains(mb_strtolower($row->keyword),'drip irrigation pipe'));
        $pipeTarget = DB::table('seo_content_backlog as b')->join('seo_keywords as k','k.id','=','b.seo_keyword_id')
            ->where('b.business_project_id',$projectId)->where('b.content_type','optimize_existing')
            ->where('k.keyword','drip irrigation pipe')->value('b.target_url');
        if ($pipeTarget && $pipeRows->isNotEmpty()) {
            DB::table('seo_content_backlog')->whereIn('id',$pipeRows->pluck('id')->all())->update([
                'content_type'=>'cluster_member','status'=>'retired','target_url'=>$pipeTarget,
                'rationale'=>'Merged into existing Drip Pipe / Line target page; use as supporting commercial/FAQ intent, not a new URL.',
                'updated_at'=>now(),
            ]);
        }

        $summary['new_content_active'] = DB::table('seo_content_backlog')->where('business_project_id',$projectId)
            ->whereIn('content_type',['blog','commercial_page','comparison_blog'])->where('status','planned')->count();
    }
    private function newContentRows(int $projectId)
    {
        return DB::table('seo_content_backlog as b')->join('seo_keywords as k','k.id','=','b.seo_keyword_id')
            ->where('b.business_project_id',$projectId)
            ->whereIn('b.content_type',['blog','commercial_page','comparison_blog'])
            ->select('b.id','b.content_type','b.status','k.keyword','k.is_competitor_term','k.topic_cluster')->get();
    }

    private function plannedNewTaskCount($rows): int
    {
        $competitor = $rows->contains(fn($row)=>(bool)$row->is_competitor_term) ? 1 : 0;
        $pipeCluster = $rows->contains(fn($row)=>!$row->is_competitor_term && str_contains(mb_strtolower($row->keyword),'drip irrigation pipe')) ? 0 : 0;
        $other = $rows->filter(fn($row)=>!$row->is_competitor_term && !str_contains(mb_strtolower($row->keyword),'drip irrigation pipe'))->count();
        return $competitor + $pipeCluster + $other;
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked']=[$reason];
        return $summary;
    }
}
