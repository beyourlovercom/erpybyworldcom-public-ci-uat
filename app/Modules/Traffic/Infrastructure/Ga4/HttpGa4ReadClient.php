<?php

namespace App\Modules\Traffic\Infrastructure\Ga4;

use App\Modules\Traffic\Application\Contracts\Ga4ReadClient;
use App\Modules\Traffic\Application\Exceptions\Ga4ReadException;
use Illuminate\Support\Facades\Http;

final class HttpGa4ReadClient implements Ga4ReadClient
{
    private ?string $accessToken = null;

    public function fetchDailyMetrics(string $propertyId, string $dateFrom, string $dateTo, string $scope): iterable
    {
        $propertyId = trim($propertyId);
        if ($propertyId === '' || ! ctype_digit($propertyId)) {
            throw new Ga4ReadException('invalid_property_id', 'GA4 numeric property id is required.');
        }

        $dimensions = match ($scope) {
            'site' => ['date'],
            'channel' => ['date', 'sessionDefaultChannelGroup'],
            'landing_page' => ['date', 'landingPagePlusQueryString'],
            'event' => ['date', 'eventName'],
            default => throw new Ga4ReadException('invalid_scope', 'Unsupported GA4 scope.'),
        };

        $metricNames = $scope === 'event'
            ? ['sessions', 'eventCount']
            : ['sessions', 'totalUsers', 'newUsers', 'engagedSessions', 'keyEvents'];

        $response = Http::withToken($this->token())
            ->acceptJson()->asJson()->timeout($this->timeout())
            ->post(
                rtrim((string) config('services.google_analytics.base_url', 'https://analyticsdata.googleapis.com/v1beta'), '/')
                    .'/properties/'.$propertyId.':runReport',
                [
                    'dateRanges' => [['startDate' => $dateFrom, 'endDate' => $dateTo]],
                    'dimensions' => array_map(fn (string $name): array => ['name' => $name], $dimensions),
                    'metrics' => array_map(fn (string $name): array => ['name' => $name], $metricNames),
                    'limit' => (string) max(1, min(100000, (int) config('services.google_analytics.row_limit', 100000))),
                ],
            );

        if (! $response->successful()) {
            throw new Ga4ReadException('provider_error', 'GA4 Data API request failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new Ga4ReadException('provider_invalid_response', 'GA4 returned invalid JSON.');
        }

        foreach (($payload['rows'] ?? []) as $row) {
            if (! is_array($row)) continue;

            $dimensionValues = array_map(
                fn ($value): string => trim((string) ($value['value'] ?? '')),
                is_array($row['dimensionValues'] ?? null) ? $row['dimensionValues'] : [],
            );
            $metricValues = array_map(
                fn ($value): string => trim((string) ($value['value'] ?? '')),
                is_array($row['metricValues'] ?? null) ? $row['metricValues'] : [],
            );

            if (count($dimensionValues) !== count($dimensions) || count($metricValues) !== count($metricNames)) {
                throw new Ga4ReadException('provider_invalid_response', 'GA4 row dimensions or metrics are incomplete.');
            }

            if ($scope === 'event') {
                yield [
                    'metric_date' => $this->gaDate($dimensionValues[0]),
                    'scope' => $scope,
                    'dimension_value' => mb_substr($dimensionValues[1] ?? '', 0, 500),
                    'sessions' => $this->nonNegativeInt($metricValues[0], 'sessions'),
                    'total_users' => 0,
                    'new_users' => 0,
                    'engaged_sessions' => 0,
                    'key_events' => 0.0,
                    'event_count' => $this->nonNegativeInt($metricValues[1], 'eventCount'),
                ];
                continue;
            }

            yield [
                'metric_date' => $this->gaDate($dimensionValues[0]),
                'scope' => $scope,
                'dimension_value' => $scope === 'site' ? '' : mb_substr($dimensionValues[1] ?? '', 0, 500),
                'sessions' => $this->nonNegativeInt($metricValues[0], 'sessions'),
                'total_users' => $this->nonNegativeInt($metricValues[1], 'totalUsers'),
                'new_users' => $this->nonNegativeInt($metricValues[2], 'newUsers'),
                'engaged_sessions' => $this->nonNegativeInt($metricValues[3], 'engagedSessions'),
                'key_events' => $this->nonNegativeFloat($metricValues[4], 'keyEvents'),
                'event_count' => 0,
            ];
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;

        $injected = trim((string) config('services.google_analytics.access_token', ''));
        if ($injected !== '') {
            return $this->accessToken = $injected;
        }

        $tokenFile = trim((string) config('services.google_analytics.access_token_file', ''));
        if ($tokenFile !== '') {
            if (! is_file($tokenFile) || ! is_readable($tokenFile)) {
                throw new Ga4ReadException('credentials_missing', 'GA4 access token file is not readable.');
            }
            $fileToken = trim((string) file_get_contents($tokenFile));
            if ($fileToken === '') {
                throw new Ga4ReadException('credentials_missing', 'GA4 access token file is empty.');
            }

            return $this->accessToken = $fileToken;
        }

        $config = $this->credentials();
        $response = Http::asForm()->timeout($config['timeout'])->post(
            (string) config('services.google_analytics.oauth_url', 'https://oauth2.googleapis.com/token'),
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        );

        if (! $response->successful()) {
            throw new Ga4ReadException('authentication_failed', 'GA4 OAuth token refresh failed with HTTP '.$response->status().'.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new Ga4ReadException('authentication_failed', 'GA4 OAuth response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.google_analytics.timeout', 20));
    }

    /** @return array{client_id:string,client_secret:string,refresh_token:string,timeout:int} */
    private function credentials(): array
    {
        $config = [
            'client_id' => trim((string) config('services.google_analytics.client_id', '')),
            'client_secret' => trim((string) config('services.google_analytics.client_secret', '')),
            'refresh_token' => trim((string) config('services.google_analytics.refresh_token', '')),
            'timeout' => $this->timeout(),
        ];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $required) {
            if ($config[$required] === '') {
                throw new Ga4ReadException('credentials_missing', 'GA4 OAuth credentials are not configured.');
            }
        }

        return $config;
    }

    private function gaDate(string $value): string
    {
        if (! preg_match('/^\d{8}$/', $value)) {
            throw new Ga4ReadException('provider_invalid_response', 'GA4 returned an invalid date.');
        }

        return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
    }

    private function nonNegativeInt(string $value, string $field): int
    {
        if ($value === '' || ! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            throw new Ga4ReadException('provider_invalid_response', 'GA4 returned invalid '.$field.'.');
        }
        return (int) $value;
    }

    private function nonNegativeFloat(string $value, string $field): float
    {
        if ($value === '' || ! is_numeric($value) || (float) $value < 0) {
            throw new Ga4ReadException('provider_invalid_response', 'GA4 returned invalid '.$field.'.');
        }
        return (float) $value;
    }
}
