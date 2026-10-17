<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\Inquiries\Infrastructure\Models\CaseAttribution;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use App\Modules\Sales\Infrastructure\Models\SalesQuote;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class BossDashboardService
{
    public function __construct(
        private readonly BusinessProjectRepository $projects,
        private readonly TrafficService $traffic,
    ) {}

    public function build(string $projectKey, string $dateFrom, string $dateTo, ?array $drilldownFilter = null, ?array $timeComparisonFilter = null): array
    {
        $project = $this->projects->findByKey($projectKey);
        if ($project === null) {
            throw new InvalidArgumentException("Unknown business project [{$projectKey}].");
        }

        $projectId = (int) $project->id;
        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $toExclusive = CarbonImmutable::parse($dateTo)->addDay()->startOfDay();

        $accounts = $this->traffic->listProjectSourceAccounts($projectId);
        $trafficRows = $this->traffic->listProjectDailyTrafficMetrics($projectId, $dateFrom, $dateTo);
        $providerInquiryRows = $this->traffic->listProjectDailyInquiryMetrics($projectId, $dateFrom, $dateTo);

        $caseQuery = InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->where('first_inbound_at', '>=', $from)
            ->where('first_inbound_at', '<', $toExclusive);

        $inquiries = (clone $caseQuery)->count();
        $totalInquiries = InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->count();
        $qualifiedInquiries = (clone $caseQuery)->where('case_stage', 'qualified')->count();
        $caseRows = (clone $caseQuery)->get(['id', 'first_inbound_at', 'case_stage']);

        $opportunityQuery = SalesOpportunity::query()->where('business_project_id', $projectId);
        $opportunities = (clone $opportunityQuery)
            ->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive)->count();

        $quoted = SalesQuote::query()
            ->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive)
            ->whereHas('opportunity', fn ($q) => $q->where('business_project_id', $projectId))
            ->distinct()->count('opportunity_id');

        $wonRows = (clone $opportunityQuery)
            ->where('status', 'won')
            ->where('won_at', '>=', $from)->where('won_at', '<', $toExclusive)
            ->get(['id', 'currency', 'expected_value_micros', 'won_at']);
        $won = $wonRows->count();
        $wonValueMicros = (int) $wonRows->sum('expected_value_micros');
        $wonCurrencies = $wonRows->pluck('currency')->filter()->unique()->values();
        $wonCurrencyCode = $wonCurrencies->count() === 1 ? $wonCurrencies->first() : ($wonCurrencies->count() > 1 ? 'MIXED' : null);

        $receivedRows = SalesPaymentReceipt::query()
            ->where('business_project_id', $projectId)
            ->where('status', 'confirmed')
            ->where('received_at', '>=', $from)
            ->where('received_at', '<', $toExclusive)
            ->get(['currency', 'amount_micros', 'received_at']);
        $receivedTotals = $this->receivedTotals($receivedRows);
        $receivedCurrencyCode = count($receivedTotals) === 1 ? $receivedTotals[0]['currency'] : (count($receivedTotals) > 1 ? 'MIXED' : null);
        $receivedMicros = count($receivedTotals) === 1 ? $receivedTotals[0]['amount_micros'] : null;

        $coverageRows = CaseAttribution::query()
            ->where('business_project_id', $projectId)
            ->whereHas('case', fn ($q) => $q->where('first_inbound_at', '>=', $from)->where('first_inbound_at', '<', $toExclusive))
            ->get(['case_id', 'traffic_campaign_id', 'landing_page_id', 'source_kind', 'attribution_state']);
        $cohortOpportunities = SalesOpportunity::query()->where('business_project_id', $projectId)
            ->whereIn('case_id', $caseRows->pluck('id'))->get(['id', 'opportunity_number', 'case_id', 'status', 'currency', 'expected_value_micros', 'won_at']);
        $opportunityByCase = $cohortOpportunities->keyBy('case_id');
        $resolved = $coverageRows->where('attribution_state', 'resolved')->count();
        $partial = $coverageRows->where('attribution_state', 'partial')->count();
        $missingAttribution = max(0, $inquiries - $coverageRows->count());
        $unresolved = $coverageRows->where('attribution_state', 'unresolved')->count() + $missingAttribution;

        $currencyCodes = $accounts->pluck('currency_code')->filter()->unique()->values();
        $currencyCode = $currencyCodes->count() > 1 ? 'MIXED' : $currencyCodes->first();
        $impressions = (int) $trafficRows->sum('impressions');
        $clicks = (int) $trafficRows->sum('clicks');
        $costMicros = (int) $trafficRows->sum('cost_micros');
        $providerConversions = round($trafficRows->sum(fn ($row): float => (float) $row->provider_conversions), 4);

        $funnel = $this->funnel([
            ['key' => 'traffic', 'label' => '流量', 'count' => $clicks],
            ['key' => 'inquiry', 'label' => '询盘', 'count' => $inquiries],
            ['key' => 'qualified', 'label' => '有效询盘', 'count' => $qualifiedInquiries],
            ['key' => 'opportunity', 'label' => '商机', 'count' => $opportunities],
            ['key' => 'quoted', 'label' => '已报价', 'count' => $quoted],
            ['key' => 'won', 'label' => '成交', 'count' => $won],
        ]);

        $trafficByDate = $trafficRows->groupBy(fn ($row): string => (string) $row->metric_date);
        $caseByDate = $caseRows->groupBy(fn ($case): string => $case->first_inbound_at->toDateString());
        $wonByDate = $wonRows->groupBy(fn ($row): string => $row->won_at->toDateString());
        $receivedByDate = $receivedRows->groupBy(fn ($row): string => $row->received_at->toDateString());
        $dates = collect();
        for ($cursor = $from; $cursor->lt($toExclusive); $cursor = $cursor->addDay()) {
            $dates->push($cursor->toDateString());
        }
        $trend = $dates->map(function (string $date) use ($trafficByDate, $caseByDate, $wonByDate, $receivedByDate): array {
            $traffic = $trafficByDate->get($date, collect());
            $cases = $caseByDate->get($date, collect());
            $wonForDate = $wonByDate->get($date, collect());
            $receivedForDate = $receivedByDate->get($date, collect());
            $costMicros = (int) $traffic->sum('cost_micros');

            return [
                'date' => $date,
                'impressions' => (int) $traffic->sum('impressions'),
                'clicks' => (int) $traffic->sum('clicks'),
                'cost_micros' => $costMicros,
                'spend' => $this->money($costMicros),
                'provider_conversions' => round($traffic->sum(fn ($row): float => (float) $row->provider_conversions), 4),
                'inquiries' => $cases->count(),
                'qualified_inquiries' => $cases->where('case_stage', 'qualified')->count(),
                'won' => $wonForDate->count(),
                'received_totals' => $this->receivedTotals($receivedForDate),
            ];
        })->all();

        $actualDates = $trafficRows->pluck('metric_date')->map(fn ($date): string => (string) $date)
            ->merge($caseRows->map(fn ($case): string => $case->first_inbound_at->toDateString()))
            ->merge($wonRows->map(fn ($row): string => $row->won_at->toDateString()))
            ->merge($receivedRows->map(fn ($row): string => $row->received_at->toDateString()))
            ->filter()->sort()->values();
        $earliestActualDate = $actualDates->first();
        $latestActualDate = $actualDates->last();
        $weeklyComparison = collect($trend)
            ->when($latestActualDate, fn ($rows) => $rows->where('date', '<=', $latestActualDate), fn ($rows) => $rows->take(0))
            ->groupBy(fn (array $row): string => CarbonImmutable::parse($row['date'])->startOfWeek()->toDateString())
            ->map(function ($rows, string $weekStart) use ($dateFrom, $dateTo, $latestActualDate): array {
                $costMicros = (int) $rows->sum('cost_micros');
                $weekEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
                $periodStart = max($weekStart, $dateFrom);
                $periodEnd = min($weekEnd, $dateTo, (string) $latestActualDate);

                return [
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'is_partial' => $periodStart !== $weekStart || $periodEnd !== $weekEnd,
                    'clicks' => (int) $rows->sum('clicks'),
                    'inquiries' => (int) $rows->sum('inquiries'),
                    'cost_micros' => $costMicros,
                    'spend' => $this->money($costMicros),
                    'won' => (int) $rows->sum('won'),
                    'received_totals' => $this->mergeReceivedTotals($rows),
                ];
            })->values()->all();

        $timeComparison = $this->timeComparison(
            $projectId,
            $dateFrom,
            $dateTo,
            $trend,
            $earliestActualDate,
            $latestActualDate,
            $timeComparisonFilter,
        );

        $campaignMetricGroups = $trafficRows->groupBy('traffic_campaign_id');
        $campaignAttributionGroups = $coverageRows->whereNotNull('traffic_campaign_id')->groupBy('traffic_campaign_id');
        $campaignIds = $campaignMetricGroups->keys()->merge($campaignAttributionGroups->keys())->unique()->values();
        $campaignModels = TrafficCampaign::query()->whereIn('id', $campaignIds)->get()->keyBy('id');
        $campaigns = $campaignIds->map(function ($campaignId) use ($campaignMetricGroups, $campaignAttributionGroups, $campaignModels, $opportunityByCase): array {
            $metrics = $campaignMetricGroups->get($campaignId, collect());
            $attrs = $campaignAttributionGroups->get($campaignId, collect());
            $campaign = $campaignModels->get($campaignId);
            $costMicros = (int) $metrics->sum('cost_micros');

            return [
                'id' => (int) $campaignId, 'external_campaign_id' => (string) ($campaign?->external_campaign_id ?? ''),
                'name' => (string) ($campaign?->name ?? '未知广告活动'), 'status' => $campaign?->status,
                'advertising_channel_type' => $campaign?->advertising_channel_type,
                'impressions' => (int) $metrics->sum('impressions'), 'clicks' => (int) $metrics->sum('clicks'),
                'cost_micros' => $costMicros, 'spend' => $this->money($costMicros),
                'provider_conversions' => round($metrics->sum(fn ($row): float => (float) $row->provider_conversions), 4),
            ] + $this->outcomes($attrs->pluck('case_id'), $opportunityByCase);
        })->sortByDesc('cost_micros')->values()->all();

        $sourceGroups = $coverageRows->groupBy(fn ($row): string => (string) ($row->source_kind ?: 'unknown'));
        $sources = $sourceGroups->map(fn ($rows, string $sourceType): array => ['source_type' => $sourceType] + $this->outcomes($rows->pluck('case_id'), $opportunityByCase))->values();
        $missingCaseIds = $caseRows->pluck('id')->diff($coverageRows->pluck('case_id'));
        if ($missingCaseIds->isNotEmpty()) {
            $sources->push(['source_type' => 'unattributed'] + $this->outcomes($missingCaseIds, $opportunityByCase));
        }
        $sources = $sources->sortByDesc('inquiries')->values()->all();

        $landingGroups = $coverageRows->whereNotNull('landing_page_id')->groupBy('landing_page_id');
        $landingModels = LandingPage::query()->whereIn('id', $landingGroups->keys())->get()->keyBy('id');
        $landing_pages = $landingGroups->map(function ($rows, $landingId) use ($landingModels, $opportunityByCase): array {
            $landing = $landingModels->get($landingId);

            return [
                'id' => (int) $landingId, 'name' => (string) ($landing?->name ?? '未知落地页'),
                'path' => $landing?->path, 'canonical_url' => $landing?->canonical_url,
            ] + $this->outcomes($rows->pluck('case_id'), $opportunityByCase);
        })->sortByDesc('inquiries')->values()->all();

        $drilldown = $this->drilldown($projectKey, $drilldownFilter, $caseRows, $coverageRows, $opportunityByCase, $campaignModels, $landingModels);

        $dataDates = $trafficRows->pluck('metric_date')
            ->merge($caseRows->map(fn ($case): string => $case->first_inbound_at->toDateString()))
            ->merge($receivedRows->map(fn ($row): string => $row->received_at->toDateString()))
            ->map(fn ($date): string => (string) $date)->sort()->values();
        $lastSyncedAt = $accounts->map(fn ($account): ?string => $account->last_synced_at?->toISOString())->filter()->sort()->last();
        $singleCurrency = $currencyCode !== 'MIXED';

        return [
            'project' => ['key' => (string) $project->project_key, 'name' => (string) $project->name],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'metrics_contract' => [
                'version' => 'boss-growth-v1',
                'qualified_semantics' => 'current_case_stage_for_inbound_cohort',
                'won_value_is_revenue' => false,
                'received_is_revenue' => false,
                'received_state' => 'available_from_confirmed_payment_receipts',
                'revenue_state' => 'recognition_not_defined',
            ],
            'summary' => [
                'currency_code' => $currencyCode, 'mixed_currency' => $currencyCode === 'MIXED',
                'impressions' => $impressions, 'clicks' => $clicks,
                'cost_micros' => $costMicros, 'spend' => $singleCurrency ? $this->money($costMicros) : null,
                'provider_conversions' => $providerConversions,
                'inquiries' => $inquiries, 'total_inquiries' => $totalInquiries, 'qualified_inquiries' => $qualifiedInquiries,
                'opportunities' => $opportunities, 'quoted' => $quoted, 'won' => $won,
                'won_value_micros' => $wonValueMicros,
                'won_value' => $wonCurrencyCode !== 'MIXED' ? $this->money($wonValueMicros) : null,
                'won_currency_code' => $wonCurrencyCode,
                'received_totals' => $receivedTotals,
                'received_micros' => $receivedMicros,
                'received' => $receivedMicros !== null ? $this->money($receivedMicros) : null,
                'received_currency_code' => $receivedCurrencyCode,
                'revenue' => null,
                'cost_per_inquiry' => $singleCurrency && $inquiries > 0 ? number_format(($costMicros / 1_000_000) / $inquiries, 2, '.', '') : null,
                'ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc' => $singleCurrency && $clicks > 0 ? number_format(($costMicros / 1_000_000) / $clicks, 2, '.', '') : null,
            ],
            'funnel' => $funnel,
            'trend' => $trend,
            'weekly_comparison' => $weeklyComparison,
            'time_comparison' => $timeComparison,
            'campaigns' => $campaigns,
            'sources' => $sources,
            'landing_pages' => $landing_pages,
            'drilldown' => $drilldown,
            'attribution_quality' => ['resolved' => $resolved, 'partial' => $partial, 'unresolved' => $unresolved, 'total' => $inquiries, 'captured' => $coverageRows->count(), 'missing' => $missingAttribution],
            'data_health' => [
                'traffic_rows' => $trafficRows->count(), 'provider_inquiry_rows' => $providerInquiryRows->count(),
                'canonical_case_rows' => $caseRows->count(), 'data_from' => $dataDates->first(), 'data_to' => $dataDates->last(),
                'account_last_synced_at' => $lastSyncedAt, 'sync_mode' => 'manual', 'automatic_sync_enabled' => false,
                'qualification_state' => 'canonical_case_current_stage', 'attribution_scope' => 'canonical_case_attribution',
            ],
        ];
    }

    private function timeComparison(
        int $projectId,
        string $dateFrom,
        string $dateTo,
        array $currentTrend,
        ?string $earliestActualDate,
        ?string $latestActualDate,
        ?array $filter,
    ): array {
        $compareMode = (string) ($filter['compare_mode'] ?? 'previous');
        $requestedGranularity = (string) ($filter['granularity'] ?? 'auto');
        $granularity = $requestedGranularity === 'auto'
            ? $this->automaticGranularity($dateFrom, $dateTo)
            : $requestedGranularity;

        $currentBuckets = $this->comparisonBuckets(
            $currentTrend,
            $dateFrom,
            $dateTo,
            $granularity,
            $earliestActualDate,
            $latestActualDate,
        );

        $compareRange = $this->comparisonRange(
            $dateFrom,
            $dateTo,
            $compareMode,
            $filter['compare_from'] ?? null,
            $filter['compare_to'] ?? null,
        );

        $compareBuckets = [];
        $compareEarliestActualDate = null;
        $compareLatestActualDate = null;
        if ($compareRange !== null) {
            $comparisonDaily = $this->comparisonDailyMetrics(
                $projectId,
                $compareRange['date_from'],
                $compareRange['date_to'],
            );
            $compareEarliestActualDate = $comparisonDaily['earliest_actual_date'];
            $compareLatestActualDate = $comparisonDaily['latest_actual_date'];
            $compareBuckets = $this->comparisonBuckets(
                $comparisonDaily['rows'],
                $compareRange['date_from'],
                $compareRange['date_to'],
                $granularity,
                $compareEarliestActualDate,
                $compareLatestActualDate,
            );
        }

        return [
            'version' => 'boss-time-comparison-v2',
            'compare_mode' => $compareMode,
            'requested_granularity' => $requestedGranularity,
            'granularity' => $granularity,
            'current_range' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'effective_from' => $earliestActualDate !== null ? max($dateFrom, $earliestActualDate) : null,
                'effective_to' => $latestActualDate !== null ? min($dateTo, $latestActualDate) : null,
            ],
            'compare_range' => $compareRange === null ? null : [
                'date_from' => $compareRange['date_from'],
                'date_to' => $compareRange['date_to'],
                'effective_from' => $compareEarliestActualDate !== null ? max($compareRange['date_from'], $compareEarliestActualDate) : null,
                'effective_to' => $compareLatestActualDate !== null ? min($compareRange['date_to'], $compareLatestActualDate) : null,
            ],
            'summary' => [
                'current' => $this->comparisonTotals($currentBuckets),
                'compare' => $compareRange === null || $compareBuckets === [] ? null : $this->comparisonTotals($compareBuckets),
            ],
            'series' => [
                'current' => $currentBuckets,
                'compare' => $compareBuckets,
            ],
        ];
    }

    private function comparisonDailyMetrics(int $projectId, string $dateFrom, string $dateTo): array
    {
        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $toExclusive = CarbonImmutable::parse($dateTo)->addDay()->startOfDay();

        $trafficRows = $this->traffic->listProjectDailyTrafficMetrics($projectId, $dateFrom, $dateTo);
        $caseRows = InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->where('first_inbound_at', '>=', $from)
            ->where('first_inbound_at', '<', $toExclusive)
            ->get(['id', 'first_inbound_at']);
        $wonRows = SalesOpportunity::query()
            ->where('business_project_id', $projectId)
            ->where('status', 'won')
            ->where('won_at', '>=', $from)
            ->where('won_at', '<', $toExclusive)
            ->get(['id', 'won_at']);
        $receivedRows = SalesPaymentReceipt::query()
            ->where('business_project_id', $projectId)
            ->where('status', 'confirmed')
            ->where('received_at', '>=', $from)
            ->where('received_at', '<', $toExclusive)
            ->get(['currency', 'amount_micros', 'received_at']);

        $trafficByDate = $trafficRows->groupBy(fn ($row): string => (string) $row->metric_date);
        $caseByDate = $caseRows->groupBy(fn ($case): string => $case->first_inbound_at->toDateString());
        $wonByDate = $wonRows->groupBy(fn ($row): string => $row->won_at->toDateString());
        $receivedByDate = $receivedRows->groupBy(fn ($row): string => $row->received_at->toDateString());

        $rows = collect();
        for ($cursor = $from; $cursor->lt($toExclusive); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $traffic = $trafficByDate->get($date, collect());
            $costMicros = (int) $traffic->sum('cost_micros');
            $rows->push([
                'date' => $date,
                'clicks' => (int) $traffic->sum('clicks'),
                'inquiries' => $caseByDate->get($date, collect())->count(),
                'cost_micros' => $costMicros,
                'spend' => $this->money($costMicros),
                'won' => $wonByDate->get($date, collect())->count(),
                'received_totals' => $this->receivedTotals($receivedByDate->get($date, collect())),
            ]);
        }

        $actualDates = $trafficRows->pluck('metric_date')->map(fn ($date): string => (string) $date)
            ->merge($caseRows->map(fn ($case): string => $case->first_inbound_at->toDateString()))
            ->merge($wonRows->map(fn ($row): string => $row->won_at->toDateString()))
            ->merge($receivedRows->map(fn ($row): string => $row->received_at->toDateString()))
            ->filter()->sort()->values();

        return [
            'rows' => $rows->all(),
            'earliest_actual_date' => $actualDates->first(),
            'latest_actual_date' => $actualDates->last(),
        ];
    }

    private function comparisonBuckets(
        array $dailyRows,
        string $dateFrom,
        string $dateTo,
        string $granularity,
        ?string $earliestActualDate,
        ?string $latestActualDate,
    ): array {
        if ($earliestActualDate === null || $latestActualDate === null) {
            return [];
        }

        return collect($dailyRows)
            ->where('date', '>=', max($dateFrom, $earliestActualDate))
            ->where('date', '<=', min($dateTo, $latestActualDate))
            ->groupBy(function (array $row) use ($granularity): string {
                $date = CarbonImmutable::parse($row['date']);

                return match ($granularity) {
                    'day' => $date->toDateString(),
                    'week' => $date->startOfWeek()->toDateString(),
                    'month' => $date->startOfMonth()->toDateString(),
                    'year' => $date->startOfYear()->toDateString(),
                    default => $date->startOfWeek()->toDateString(),
                };
            })
            ->map(function ($rows, string $bucketStart) use ($dateFrom, $dateTo, $earliestActualDate, $latestActualDate, $granularity): array {
                $naturalStart = CarbonImmutable::parse($bucketStart);
                $naturalEnd = match ($granularity) {
                    'day' => $naturalStart,
                    'week' => $naturalStart->addDays(6),
                    'month' => $naturalStart->endOfMonth(),
                    'year' => $naturalStart->endOfYear(),
                    default => $naturalStart->addDays(6),
                };
                $bucketEnd = $naturalEnd->toDateString();
                $periodStart = max($bucketStart, $dateFrom, $earliestActualDate);
                $periodEnd = min($bucketEnd, $dateTo, $latestActualDate);
                $costMicros = (int) $rows->sum('cost_micros');

                return [
                    'bucket_start' => $bucketStart,
                    'bucket_end' => $bucketEnd,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'is_partial' => $periodStart !== $bucketStart || $periodEnd !== $bucketEnd,
                    'clicks' => (int) $rows->sum('clicks'),
                    'inquiries' => (int) $rows->sum('inquiries'),
                    'cost_micros' => $costMicros,
                    'spend' => $this->money($costMicros),
                    'won' => (int) $rows->sum('won'),
                    'received_totals' => $this->mergeReceivedTotals($rows),
                ];
            })
            ->values()
            ->all();
    }

    private function comparisonTotals(array $rows): array
    {
        $rows = collect($rows);
        $costMicros = (int) $rows->sum('cost_micros');

        return [
            'clicks' => (int) $rows->sum('clicks'),
            'inquiries' => (int) $rows->sum('inquiries'),
            'cost_micros' => $costMicros,
            'spend' => $this->money($costMicros),
            'won' => (int) $rows->sum('won'),
            'received_totals' => $this->mergeReceivedTotals($rows),
        ];
    }

    private function comparisonRange(
        string $dateFrom,
        string $dateTo,
        string $mode,
        ?string $customFrom,
        ?string $customTo,
    ): ?array {
        if ($mode === 'none') {
            return null;
        }

        $from = CarbonImmutable::parse($dateFrom);
        $to = CarbonImmutable::parse($dateTo);

        if ($mode === 'yoy') {
            return [
                'date_from' => $from->subYearNoOverflow()->toDateString(),
                'date_to' => $to->subYearNoOverflow()->toDateString(),
            ];
        }

        if ($mode === 'custom' && $customFrom !== null && $customTo !== null) {
            return ['date_from' => $customFrom, 'date_to' => $customTo];
        }

        $days = $from->diffInDays($to) + 1;
        $compareTo = $from->subDay();

        return [
            'date_from' => $compareTo->subDays($days - 1)->toDateString(),
            'date_to' => $compareTo->toDateString(),
        ];
    }

    private function automaticGranularity(string $dateFrom, string $dateTo): string
    {
        $days = CarbonImmutable::parse($dateFrom)->diffInDays(CarbonImmutable::parse($dateTo)) + 1;

        return match (true) {
            $days <= 14 => 'day',
            $days <= 120 => 'week',
            $days <= 1095 => 'month',
            default => 'year',
        };
    }

    private function funnel(array $rows): array
    {
        $previous = null;

        return collect($rows)->map(function (array $row) use (&$previous): array {
            $count = (int) $row['count'];
            $rate = $previous !== null && $previous > 0 ? round(($count / $previous) * 100, 2) : null;
            $previous = $count;

            return $row + ['conversion_rate' => $rate];
        })->all();
    }

    private function drilldown(string $projectKey, ?array $filter, $caseRows, $coverageRows, $opportunityByCase, $campaignModels, $landingModels): ?array
    {
        if ($filter === null) {
            return null;
        }
        $dimension = (string) $filter['dimension'];
        $value = (string) $filter['value'];
        if ($dimension === 'campaign') {
            $selected = $coverageRows->where('traffic_campaign_id', (int) $value)->pluck('case_id');
            $label = (string) ($campaignModels->get((int) $value)?->name ?? 'Campaign');
        } elseif ($dimension === 'landing') {
            $selected = $coverageRows->where('landing_page_id', (int) $value)->pluck('case_id');
            $label = (string) ($landingModels->get((int) $value)?->name ?? 'Landing Page');
        } elseif ($value === 'unattributed') {
            $selected = $caseRows->pluck('id')->diff($coverageRows->pluck('case_id'));
            $label = 'Unattributed';
        } else {
            $selected = $coverageRows->where('source_kind', $value)->pluck('case_id');
            $label = $value;
        }
        $attrs = $coverageRows->keyBy('case_id');
        $cases = InquiryCase::query()->with('company:id,name')->whereIn('id', $selected)->orderByDesc('first_inbound_at')->get(['id', 'case_number', 'company_id', 'title', 'case_stage', 'first_inbound_at']);
        $rows = $cases->map(function ($case) use ($attrs, $opportunityByCase, $projectKey): array {
            $attr = $attrs->get($case->id);
            $opp = $opportunityByCase->get($case->id);

            return [
                'case_id' => (int) $case->id, 'case_number' => (string) $case->case_number, 'company_name' => $case->company?->name, 'case_stage' => $case->case_stage,
                'first_inbound_at' => $case->first_inbound_at?->toISOString(), 'source_kind' => $attr?->source_kind ?? 'unattributed', 'attribution_state' => $attr?->attribution_state ?? 'unresolved',
                'opportunity_id' => $opp?->id, 'opportunity_number' => $opp?->opportunity_number, 'opportunity_status' => $opp?->status, 'won_at' => $opp?->won_at?->toISOString(),
                'sales_url' => $opp ? "/projects/{$projectKey}/sales/opportunities/{$opp->id}" : null,
            ];
        })->all();

        return ['dimension' => $dimension, 'value' => $value, 'label' => $label, 'total' => count($rows), 'rows' => $rows];
    }

    private function outcomes($caseIds, $opportunityByCase): array
    {
        $opportunities = collect($caseIds)->map(fn ($caseId) => $opportunityByCase->get($caseId))->filter()->values();
        $won = $opportunities->where('status', 'won')->values();
        $currencies = $won->pluck('currency')->filter()->unique()->values();
        $currency = $currencies->count() === 1 ? $currencies->first() : ($currencies->count() > 1 ? 'MIXED' : null);
        $micros = (int) $won->sum('expected_value_micros');

        return [
            'inquiries' => collect($caseIds)->unique()->count(),
            'opportunities' => $opportunities->count(),
            'won' => $won->count(),
            'won_value_micros' => $micros,
            'won_value' => $currency !== 'MIXED' ? $this->money($micros) : null,
            'won_currency_code' => $currency,
        ];
    }

    private function receivedTotals($rows): array
    {
        return collect($rows)
            ->groupBy(fn ($row): string => (string) (is_array($row) ? $row['currency'] : $row->currency))
            ->map(function ($currencyRows, string $currency): array {
                $micros = (int) $currencyRows->sum(fn ($row): int => (int) (is_array($row) ? $row['amount_micros'] : $row->amount_micros));

                return [
                    'currency' => $currency,
                    'amount_micros' => $micros,
                    'amount' => $this->money($micros),
                    'receipt_count' => $currencyRows->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    private function mergeReceivedTotals($rows): array
    {
        return collect($rows)
            ->flatMap(fn ($row) => $row['received_totals'] ?? [])
            ->groupBy('currency')
            ->map(function ($currencyRows, string $currency): array {
                $micros = (int) $currencyRows->sum('amount_micros');

                return [
                    'currency' => $currency,
                    'amount_micros' => $micros,
                    'amount' => $this->money($micros),
                    'receipt_count' => (int) $currencyRows->sum('receipt_count'),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    private function money(int $micros): string
    {
        return number_format($micros / 1_000_000, 2, '.', '');
    }
}
