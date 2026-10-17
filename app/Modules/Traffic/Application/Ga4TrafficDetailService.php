<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use InvalidArgumentException;

final class Ga4TrafficDetailService
{
    public function __construct(private readonly BusinessProjectRepository $projects) {}

    /** @return array<string, mixed> */
    public function build(string $projectKey, string $dateFrom, string $dateTo): array
    {
        $project = $this->projects->findByKey($projectKey);
        if ($project === null) {
            throw new InvalidArgumentException("Unknown business project [{$projectKey}].");
        }

        $projectId = (int) $project->id;

        $siteRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'site')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->orderBy('metric_date')
            ->get();

        $channelRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'channel')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->get();

        $landingRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'landing_page')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->get();

        $eventRows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'event')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->get();

        $sessions = (int) $siteRows->sum('sessions');
        $engagedSessions = (int) $siteRows->sum('engaged_sessions');
        $keyEvents = round($siteRows->sum(fn ($row): float => (float) $row->key_events), 4);
        $businessEvents = ['generate_lead', 'click_whatsapp'];
        $businessConversionEvents = (int) $eventRows
            ->whereIn('dimension_value', $businessEvents)
            ->sum('event_count');

        $trafficMixTotal = (int) $channelRows->sum('sessions');
        $trafficMix = $channelRows
            ->groupBy('dimension_value')
            ->map(function ($rows, string $label) use ($trafficMixTotal): array {
                $rowSessions = (int) $rows->sum('sessions');

                return [
                    'label' => $label === '' ? '(not set)' : $label,
                    'sessions' => $rowSessions,
                    'engaged_sessions' => (int) $rows->sum('engaged_sessions'),
                    'share' => $trafficMixTotal > 0 ? round(($rowSessions / $trafficMixTotal) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('sessions')
            ->values()
            ->all();

        $topLandingPages = $landingRows
            ->groupBy('dimension_value')
            ->map(function ($rows, string $path): array {
                $rowSessions = (int) $rows->sum('sessions');
                $rowEngaged = (int) $rows->sum('engaged_sessions');

                return [
                    'path' => $path === '' ? '(not set)' : $path,
                    'sessions' => $rowSessions,
                    'engaged_sessions' => $rowEngaged,
                    'engagement_rate' => $rowSessions > 0 ? round(($rowEngaged / $rowSessions) * 100, 2) : 0.0,
                    'key_events' => round($rows->sum(fn ($row): float => (float) $row->key_events), 4),
                ];
            })
            ->sortByDesc('sessions')
            ->take(20)
            ->values()
            ->all();

        $events = $eventRows
            ->groupBy('dimension_value')
            ->map(function ($rows, string $eventName) use ($businessEvents): array {
                return [
                    'event_name' => $eventName,
                    'event_count' => (int) $rows->sum('event_count'),
                    'sessions' => (int) $rows->sum('sessions'),
                    'business_conversion' => in_array($eventName, $businessEvents, true),
                ];
            })
            ->sortByDesc('event_count')
            ->take(30)
            ->values()
            ->all();

        return [
            'project' => [
                'key' => (string) $project->project_key,
                'name' => (string) $project->name,
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => [
                'sessions' => $sessions,
                'engaged_sessions' => $engagedSessions,
                'engagement_rate' => $sessions > 0 ? round(($engagedSessions / $sessions) * 100, 2) : 0.0,
                'key_events' => $keyEvents,
                'business_conversion_events' => $businessConversionEvents,
                'days_with_data' => $siteRows->count(),
            ],
            'trend' => $siteRows->map(fn ($row): array => [
                'date' => (string) $row->metric_date,
                'sessions' => (int) $row->sessions,
                'engaged_sessions' => (int) $row->engaged_sessions,
                'key_events' => round((float) $row->key_events, 4),
            ])->values()->all(),
            'traffic_mix' => $trafficMix,
            'landing_pages' => $topLandingPages,
            'events' => $events,
            'contract' => [
                'source' => 'ga4',
                'scope' => 'date_scoped',
                'business_conversion_events' => $businessEvents,
                'revenue_state' => 'excluded',
            ],
        ];
    }
}
