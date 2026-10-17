<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\YouTubeReadClient;
use App\Modules\Traffic\Application\Exceptions\YouTubeReadException;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class YouTubeTrafficSyncService
{
    public function __construct(
        private readonly YouTubeReadClient $client,
        private readonly TrafficService $traffic,
        private readonly TrafficNativeMetricService $nativeMetrics,
    ) {}

    /** @return array<string, mixed> */
    public function sync(
        string $projectKey,
        string $channelId,
        string $dateFrom,
        string $dateTo,
        string $mode = 'dry_run',
    ): array {
        $summary = [
            'mode' => $mode,
            'project_key' => $projectKey,
            'channel_id' => $channelId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'rows_seen' => 0,
            'account_rows' => 0,
            'content_rows' => 0,
            'rows_applied' => 0,
            'blocked' => [],
        ];

        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }
        if (! preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $channelId)
            || ! $this->validDate($dateFrom)
            || ! $this->validDate($dateTo)
            || $dateFrom > $dateTo) {
            return $this->blocked($summary, 'invalid_input');
        }

        $project = BusinessProject::query()
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->first();

        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }

        try {
            $channel = $this->client->fetchChannel($channelId);
            if (($channel['external_account_id'] ?? null) !== $channelId) {
                return $this->blocked($summary, 'source_account_mismatch');
            }

            $rows = [];
            foreach ($this->client->fetchDailyMetrics($channelId, $dateFrom, $dateTo) as $row) {
                if (! is_array($row) || ! $this->validRow($row, $dateFrom, $dateTo)) {
                    return $this->blocked($summary, 'invalid_metric_row');
                }

                $key = implode('|', [
                    (string) $row['metric_date'],
                    (string) $row['scope'],
                    (string) $row['external_object_id'],
                ]);
                $rows[$key] = $row;
                $summary['rows_seen']++;
                $summary[$row['scope'].'_rows']++;
            }
        } catch (YouTubeReadException $e) {
            return $this->blocked($summary, $e->status);
        }

        if ($mode === 'dry_run') {
            return $summary;
        }

        return DB::transaction(function () use ($summary, $project, $channelId, $channel, $rows): array {
            $result = $summary;
            $sourceAccount = $this->traffic->upsertTrafficSourceAccount(
                (int) $project->id,
                'youtube',
                'youtube:'.$channelId,
                [
                    'name' => $channel['name'] ?? ('YouTube '.$channelId),
                    'currency_code' => null,
                    'account_timezone' => $channel['account_timezone'] ?? null,
                    'status' => 'active',
                    'last_synced_at' => now(),
                ],
            );

            if (! $sourceAccount instanceof TrafficSourceAccount) {
                throw new InvalidArgumentException('YouTube source account persistence did not return the expected model.');
            }

            foreach ($rows as $row) {
                $this->nativeMetrics->upsert($sourceAccount, $row);
                $result['rows_applied']++;
            }

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
    private function validRow(array $row, string $from, string $to): bool
    {
        $date = (string) ($row['metric_date'] ?? '');
        $scope = (string) ($row['scope'] ?? '');
        $externalObjectId = $row['external_object_id'] ?? null;

        if (! $this->validDate($date) || $date < $from || $date > $to) {
            return false;
        }
        if (! in_array($scope, ['account', 'content'], true)) {
            return false;
        }
        if (! is_string($externalObjectId) || mb_strlen($externalObjectId) > 191) {
            return false;
        }

        foreach ([
            'impressions', 'reach', 'engagements', 'link_clicks', 'video_views',
            'watch_time_seconds', 'followers', 'spend_micros',
        ] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                return false;
            }
        }

        $followersDelta = $row['followers_delta'] ?? null;
        if ($followersDelta !== null && ! is_int($followersDelta)) {
            return false;
        }

        $providerMetrics = $row['provider_metrics'] ?? null;

        return $providerMetrics === null || is_array($providerMetrics);
    }
}
