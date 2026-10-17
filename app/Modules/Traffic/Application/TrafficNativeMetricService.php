<?php

namespace App\Modules\Traffic\Application;

use App\Modules\Traffic\Infrastructure\Models\TrafficNativeDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use DateTimeImmutable;
use InvalidArgumentException;

final class TrafficNativeMetricService
{
    public const PROVIDERS = ['meta', 'youtube', 'tiktok', 'other_social'];
    public const SCOPES = ['account', 'content', 'campaign'];

    /** @param array<string, mixed> $metric */
    public function upsert(TrafficSourceAccount $account, array $metric): TrafficNativeDailyMetric
    {
        if (! in_array((string) $account->provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unsupported native traffic provider.');
        }

        $date = trim((string) ($metric['metric_date'] ?? ''));
        $scope = trim((string) ($metric['scope'] ?? 'account'));
        $externalObjectId = trim((string) ($metric['external_object_id'] ?? ''));

        if (! $this->validDate($date)) {
            throw new InvalidArgumentException('Native traffic metric date must use YYYY-MM-DD.');
        }
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unsupported native traffic metric scope.');
        }
        if (mb_strlen($externalObjectId) > 191) {
            throw new InvalidArgumentException('Native traffic external object id is too long.');
        }

        $values = [
            'object_name' => $this->nullableString($metric['object_name'] ?? null, 255),
            'impressions' => $this->nullableNonNegativeInt($metric['impressions'] ?? null, 'impressions'),
            'reach' => $this->nullableNonNegativeInt($metric['reach'] ?? null, 'reach'),
            'engagements' => $this->nullableNonNegativeInt($metric['engagements'] ?? null, 'engagements'),
            'link_clicks' => $this->nullableNonNegativeInt($metric['link_clicks'] ?? null, 'link_clicks'),
            'video_views' => $this->nullableNonNegativeInt($metric['video_views'] ?? null, 'video_views'),
            'watch_time_seconds' => $this->nullableNonNegativeInt($metric['watch_time_seconds'] ?? null, 'watch_time_seconds'),
            'followers' => $this->nullableNonNegativeInt($metric['followers'] ?? null, 'followers'),
            'followers_delta' => $this->nullableSignedInt($metric['followers_delta'] ?? null, 'followers_delta'),
            'spend_micros' => $this->nullableNonNegativeInt($metric['spend_micros'] ?? null, 'spend_micros'),
            'provider_metrics' => $this->providerMetrics($metric['provider_metrics'] ?? null),
        ];

        return TrafficNativeDailyMetric::query()->updateOrCreate(
            [
                'traffic_source_account_id' => $account->id,
                'metric_date' => $date,
                'scope' => $scope,
                'external_object_id' => $externalObjectId,
            ],
            $values,
        );
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Native traffic object name is too long.');
        }

        return $value;
    }

    private function nullableNonNegativeInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Native traffic {$field} must be a non-negative integer or null.");
        }

        return $value;
    }

    private function nullableSignedInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value)) {
            throw new InvalidArgumentException("Native traffic {$field} must be an integer or null.");
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function providerMetrics(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Native traffic provider_metrics must be an array or null.');
        }

        return $value;
    }
}
