<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\Ga4ReadClient;
use App\Modules\Traffic\Application\Exceptions\Ga4ReadException;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class Ga4TrafficSyncService
{
    public function __construct(private readonly Ga4ReadClient $client) {}

    /** @return array<string, mixed> */
    public function sync(string $projectKey, string $propertyId, string $dateFrom, string $dateTo, string $mode = 'dry_run'): array
    {
        $summary = [
            'mode' => $mode, 'project_key' => $projectKey, 'property_id' => $propertyId,
            'date_from' => $dateFrom, 'date_to' => $dateTo,
            'rows_seen' => 0, 'site_rows' => 0, 'channel_rows' => 0,
            'landing_page_rows' => 0, 'event_rows' => 0, 'rows_applied' => 0, 'blocked' => [],
        ];

        if (! in_array($mode, ['dry_run', 'apply'], true)) return $this->blocked($summary, 'invalid_mode');
        if (! ctype_digit($propertyId) || ! $this->validDate($dateFrom) || ! $this->validDate($dateTo) || $dateFrom > $dateTo) {
            return $this->blocked($summary, 'invalid_input');
        }

        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        if (! $project) return $this->blocked($summary, 'project_not_found');

        try {
            $rows = [];
            foreach (['site', 'channel', 'landing_page', 'event'] as $scope) {
                foreach ($this->client->fetchDailyMetrics($propertyId, $dateFrom, $dateTo, $scope) as $row) {
                    if (! is_array($row) || ! $this->validRow($row, $dateFrom, $dateTo, $scope)) {
                        return $this->blocked($summary, 'invalid_metric_row');
                    }
                    $key = implode('|', [$row['metric_date'], $scope, $row['dimension_value']]);
                    $rows[$key] = $row;
                    $summary['rows_seen']++;
                    $summary[$scope.'_rows']++;
                }
            }
        } catch (Ga4ReadException $e) {
            return $this->blocked($summary, $e->status);
        }

        if ($mode === 'dry_run') return $summary;

        return DB::transaction(function () use ($summary, $project, $propertyId, $rows): array {
            $result = $summary;
            foreach ($rows as $row) {
                TrafficGa4DailyMetric::query()->updateOrCreate(
                    [
                        'business_project_id' => $project->id,
                        'metric_date' => $row['metric_date'],
                        'scope' => $row['scope'],
                        'dimension_value' => $row['dimension_value'],
                    ],
                    [
                        'sessions' => $row['sessions'],
                        'total_users' => $row['total_users'],
                        'new_users' => $row['new_users'],
                        'engaged_sessions' => $row['engaged_sessions'],
                        'key_events' => $row['key_events'],
                        'event_count' => $row['event_count'] ?? 0,
                    ],
                );
                $result['rows_applied']++;
            }

            app(TrafficService::class)->upsertTrafficSourceAccount(
                (int) $project->id,
                'ga4',
                $propertyId,
                [
                    'name' => 'GA4 Property '.$propertyId,
                    'status' => 'active',
                    'last_synced_at' => now(),
                ],
            );

            return $result;
        });
    }

    /** @param array<string, mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];
        return $summary;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $row */
    private function validRow(array $row, string $from, string $to, string $scope): bool
    {
        if (($row['scope'] ?? null) !== $scope || ! $this->validDate((string) ($row['metric_date'] ?? ''))) return false;
        if ($row['metric_date'] < $from || $row['metric_date'] > $to) return false;
        if (! is_string($row['dimension_value'] ?? null) || mb_strlen($row['dimension_value']) > 500) return false;

        foreach (['sessions', 'total_users', 'new_users', 'engaged_sessions'] as $field) {
            if (! is_int($row[$field] ?? null) || $row[$field] < 0) return false;
        }

        if (! is_numeric($row['key_events'] ?? null) || (float) $row['key_events'] < 0) return false;
        return is_int($row['event_count'] ?? 0) && ($row['event_count'] ?? 0) >= 0;
    }
}
