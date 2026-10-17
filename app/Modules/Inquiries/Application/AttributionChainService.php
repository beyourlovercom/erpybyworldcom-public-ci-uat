<?php

namespace App\Modules\Inquiries\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AttributionChainService
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    public function project(string $projectKey): array
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        $rows = DB::table('case_attributions as a')
            ->join('cases as c', 'c.id', '=', 'a.case_id')
            ->leftJoin('sales_opportunities as o', 'o.case_id', '=', 'c.id')
            ->where('c.business_project_id', $project->id)
            ->orderBy('c.id')
            ->get([
                'c.id as case_id', 'c.case_number', 'a.source_kind',
                'a.attribution_state', 'a.traffic_campaign_id', 'a.landing_page_id',
                'o.id as opportunity_id', 'o.opportunity_number', 'o.status as opportunity_status',
                'o.currency', 'o.expected_value_micros', 'o.won_at',
            ]);

        return [
            'project_key' => $projectKey,
            'cases' => $rows->count(),
            'opportunities' => $rows->whereNotNull('opportunity_id')->count(),
            'won' => $rows->where('opportunity_status', 'won')->count(),
            'won_value_micros' => (int) $rows->where('opportunity_status', 'won')->sum('expected_value_micros'),
            'rows' => $rows->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
