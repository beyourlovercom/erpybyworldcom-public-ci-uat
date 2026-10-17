<?php

namespace App\Modules\Content\Infrastructure\Yby;

use App\Modules\Content\Application\Contracts\InternalLinkProvider;
use App\Modules\Content\Application\Data\ArticleBaseData;
use Illuminate\Support\Facades\DB;

final class YbyInternalLinkProvider implements InternalLinkProvider
{
    public function linksFor(ArticleBaseData $article): array
    {
        $projectId = DB::table('business_projects')
            ->where('project_key', $article->projectKey)
            ->value('id');

        if (! $projectId) {
            return [];
        }

        return DB::table('seo_optimization_publications as p')
            ->join('seo_optimization_work_orders as w', 'w.id', '=', 'p.seo_optimization_work_order_id')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'w.primary_seo_keyword_id')
            ->where('w.business_project_id', $projectId)
            ->whereNotNull('p.published_url')
            ->when($article->canonicalPath, fn ($query, $path) => $query->where('p.published_url', 'not like', '%'.$path))
            ->orderByDesc('p.publish_date')
            ->limit(8)
            ->get(['p.published_url', 'k.keyword'])
            ->map(fn ($row): array => [
                'url' => (string) $row->published_url,
                'anchor_direction' => $row->keyword ? (string) $row->keyword : null,
                'source' => 'seo_publication',
            ])
            ->all();
    }
}
