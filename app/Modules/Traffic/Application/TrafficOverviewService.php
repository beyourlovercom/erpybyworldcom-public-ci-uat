<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Traffic\Infrastructure\Models\SeoKeywordEvidence;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TrafficOverviewService
{
    public function __construct(
        private readonly BusinessProjectRepository $projects,
        private readonly TrafficService $traffic,
    ) {}

    public function build(string $projectKey, string $dateFrom, string $dateTo): array
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
        $caseRows = InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->where('first_inbound_at', '>=', $from)
            ->where('first_inbound_at', '<', $toExclusive)
            ->get(['id', 'first_inbound_at']);
        $inquiries = $caseRows->count();

        $adsRows = $trafficRows->filter(fn ($row) => (string) ($row->campaign?->sourceAccount?->provider ?? '') === 'google_ads');
        $adsImpressions = (int) $adsRows->sum('impressions');
        $adsClicks = (int) $adsRows->sum('clicks');
        $adsCostMicros = (int) $adsRows->sum('cost_micros');

        $gscDailyRows = DB::table('seo_gsc_daily_metrics')
            ->join('seo_keywords', 'seo_keywords.id', '=', 'seo_gsc_daily_metrics.seo_keyword_id')
            ->where('seo_keywords.business_project_id', $projectId)
            ->whereBetween('seo_gsc_daily_metrics.metric_date', [$dateFrom, $dateTo])
            ->selectRaw(
                'seo_gsc_daily_metrics.metric_date, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.impressions),0) as impressions, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.clicks),0) as clicks'
            )
            ->groupBy('seo_gsc_daily_metrics.metric_date')
            ->orderBy('seo_gsc_daily_metrics.metric_date')
            ->get();

        $gscImpressions = (int) $gscDailyRows->sum('impressions');
        $gscClicks = (int) $gscDailyRows->sum('clicks');
        $hasGscDaily = $gscDailyRows->isNotEmpty();

        $gscTopKeywords = DB::table('seo_gsc_daily_metrics')
            ->join('seo_keywords', 'seo_keywords.id', '=', 'seo_gsc_daily_metrics.seo_keyword_id')
            ->where('seo_keywords.business_project_id', $projectId)
            ->whereBetween('seo_gsc_daily_metrics.metric_date', [$dateFrom, $dateTo])
            ->selectRaw(
                'seo_keywords.id as keyword_id, seo_keywords.keyword, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.impressions),0) as impressions, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.clicks),0) as clicks'
            )
            ->groupBy('seo_keywords.id', 'seo_keywords.keyword')
            ->orderByDesc('clicks')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get();

        $hasGscLegacy = SeoKeywordEvidence::query()
            ->where('source_type', 'google_search_console')
            ->whereHas('keyword', fn ($query) => $query->where('business_project_id', $projectId))
            ->exists();

        $ga4SiteRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'site')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->orderBy('metric_date')
            ->get();

        $ga4ChannelRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'channel')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->get();

        $ga4Sessions = (int) $ga4SiteRows->sum('sessions');
        $ga4EngagedSessions = (int) $ga4SiteRows->sum('engaged_sessions');
        $ga4KeyEvents = round($ga4SiteRows->sum(fn ($row): float => (float) $row->key_events), 4);

        $businessEventNames = ['generate_lead', 'click_whatsapp'];
        $ga4BusinessEvents = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'event')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->whereIn('dimension_value', $businessEventNames)
            ->selectRaw('dimension_value, COALESCE(SUM(event_count),0) as event_count, COALESCE(SUM(sessions),0) as sessions')
            ->groupBy('dimension_value')
            ->get();

        $businessConversionEvents = (int) $ga4BusinessEvents->sum('event_count');
        $businessEventBreakdown = $ga4BusinessEvents
            ->map(fn ($row): array => [
                'event_name' => (string) $row->dimension_value,
                'event_count' => (int) $row->event_count,
                'sessions' => (int) $row->sessions,
            ])
            ->sortByDesc('event_count')
            ->values()
            ->all();

        $providerSet = $accounts->pluck('provider')->map(fn ($value) => (string) $value)->unique();
        $hasAds = $providerSet->contains('google_ads') || $adsRows->isNotEmpty();
        $hasGsc = $hasGscDaily || $hasGscLegacy;
        $hasGa4 = $providerSet->contains('ga4') || $ga4SiteRows->isNotEmpty();

        $dates = collect();
        for ($cursor = $from; $cursor->lt($toExclusive); $cursor = $cursor->addDay()) {
            $dates->push($cursor->toDateString());
        }

        $trafficByDate = $trafficRows->groupBy(fn ($row): string => (string) $row->metric_date);
        $gscByDate = $gscDailyRows->keyBy(fn ($row): string => (string) $row->metric_date);
        $caseByDate = $caseRows->groupBy(fn ($case): string => $case->first_inbound_at->toDateString());
        $ga4ByDate = $ga4SiteRows->groupBy(fn ($row): string => (string) $row->metric_date);

        $trend = $dates->map(function (string $date) use ($trafficByDate, $gscByDate, $caseByDate, $ga4ByDate): array {
            $traffic = $trafficByDate->get($date, collect());
            $gsc = $gscByDate->get($date);
            $ga4 = $ga4ByDate->get($date, collect());

            return [
                'date' => $date,
                'clicks' => (int) $traffic->sum('clicks') + (int) ($gsc?->clicks ?? 0),
                'impressions' => (int) $traffic->sum('impressions') + (int) ($gsc?->impressions ?? 0),
                'sessions' => (int) $ga4->sum('sessions'),
                'engaged_sessions' => (int) $ga4->sum('engaged_sessions'),
                'inquiries' => $caseByDate->get($date, collect())->count(),
            ];
        })->all();

        $adsTopItems = $adsRows
            ->groupBy('traffic_campaign_id')
            ->map(function ($rows, $campaignId): array {
                $campaign = $rows->first()?->campaign;

                return [
                    'key' => 'google-ads-'.$campaignId,
                    'name' => (string) ($campaign?->name ?? 'Google Ads Campaign'),
                    'channel' => 'Google Ads',
                    'impressions' => (int) $rows->sum('impressions'),
                    'clicks' => (int) $rows->sum('clicks'),
                    'note' => '当前日期范围 · Campaign',
                ];
            });

        $gscTopItems = $gscTopKeywords->map(fn ($row): array => [
            'key' => 'gsc-keyword-'.$row->keyword_id,
            'name' => (string) $row->keyword,
            'channel' => 'SEO / GSC',
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'note' => '当前日期范围 · Keyword',
        ]);

        $topItems = $adsTopItems
            ->concat($gscTopItems)
            ->sort(function (array $a, array $b): int {
                $clickCompare = $b['clicks'] <=> $a['clicks'];

                return $clickCompare !== 0 ? $clickCompare : ($b['impressions'] <=> $a['impressions']);
            })
            ->take(10)
            ->values()
            ->all();

        $trafficMixTotal = (int) $ga4ChannelRows->sum('sessions');
        $trafficMix = $ga4ChannelRows
            ->groupBy('dimension_value')
            ->map(function ($rows, string $label) use ($trafficMixTotal): array {
                $sessions = (int) $rows->sum('sessions');

                return [
                    'label' => $label === '' ? '(not set)' : $label,
                    'sessions' => $sessions,
                    'value' => $trafficMixTotal > 0 ? round(($sessions / $trafficMixTotal) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('sessions')
            ->values()
            ->all();

        $channels = [
            $this->channel('website', '网站', 'GA4', $hasGa4, null, null, $hasGa4 ? '已接入' : '等待 GA4 授权', [
                'sessions' => $hasGa4 ? $ga4Sessions : null,
                'engaged_sessions' => $hasGa4 ? $ga4EngagedSessions : null,
                'key_events' => $hasGa4 ? $ga4KeyEvents : null,
                'business_conversion_events' => $hasGa4 ? $businessConversionEvents : null,
                'business_event_breakdown' => $hasGa4 ? $businessEventBreakdown : [],
            ]),
            $this->channel('google_ads', 'Google Ads', 'Google Ads', $hasAds, $adsImpressions, $adsClicks, $hasAds ? '已接入' : '未接入', [
                'spend' => number_format($adsCostMicros / 1_000_000, 2, '.', ''),
                'currency' => $accounts->firstWhere('provider', 'google_ads')?->currency_code,
            ]),
            $this->channel(
                'seo',
                'SEO',
                'GSC',
                $hasGsc,
                $hasGscDaily ? $gscImpressions : null,
                $hasGscDaily ? $gscClicks : null,
                $hasGscDaily ? '已接入' : ($hasGscLegacy ? '已接入（仅历史累计，无日期）' : '未接入')
            ),
            $this->channel('facebook', 'Facebook / Meta', 'Meta', false, null, null, '接入能力已就绪，等待账号授权'),
            $this->channel('youtube', 'YouTube', 'YouTube', false, null, null, '已暂缓，周一继续授权'),
            $this->channel('tiktok', 'TikTok', 'TikTok', false, null, null, '已暂缓，周一继续授权'),
        ];

        return [
            'project' => ['key' => (string) $project->project_key, 'name' => (string) $project->name],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'summary' => [
                'platform_impressions' => $adsImpressions + $gscImpressions,
                'platform_clicks' => $adsClicks + $gscClicks,
                'website_sessions' => $hasGa4 ? $ga4Sessions : null,
                'engaged_sessions' => $hasGa4 ? $ga4EngagedSessions : null,
                'key_events' => $hasGa4 ? $ga4KeyEvents : null,
                'business_conversion_events' => $hasGa4 ? $businessConversionEvents : null,
                'inquiries' => $inquiries,
                'active_channels' => collect($channels)->where('connected', true)->count(),
                'tracked_channels' => count($channels),
            ],
            'channels' => $channels,
            'trend' => $trend,
            'traffic_mix' => $trafficMix,
            'top_items' => $topItems,
            'contract' => [
                'version' => 'traffic-os-v2-cross-channel-overview-v1',
                'platform_impressions_are_cross_platform' => false,
                'platform_metric_semantics' => 'source_native',
                'summary_scope' => 'date_scoped_sources_only',
                'summary_sources' => ['google_ads', 'gsc'],
                'deferred_sources' => ['youtube', 'tiktok'],
                'website_metrics_source' => 'ga4',
                'website_metrics_state' => $hasGa4 ? 'available' : 'unavailable_until_ga4_connector',
                'gsc_metrics_state' => $hasGscDaily ? 'daily_available' : ($hasGscLegacy ? 'legacy_cumulative_only' : 'unavailable'),
                'business_conversion_events' => $businessEventNames,
                'ga4_key_event_state' => $ga4KeyEvents > 0 ? 'available' : 'not_configured_or_no_data',
                'revenue_state' => 'excluded',
            ],
        ];
    }

    private function channel(
        string $key,
        string $label,
        string $source,
        bool $connected,
        ?int $impressions,
        ?int $clicks,
        string $status,
        array $extra = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'source' => $source,
            'connected' => $connected,
            'status' => $status,
            'impressions' => $impressions,
            'clicks' => $clicks,
        ] + $extra;
    }
}
