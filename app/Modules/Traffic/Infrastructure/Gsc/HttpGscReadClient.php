<?php

namespace App\Modules\Traffic\Infrastructure\Gsc;

use App\Modules\Traffic\Application\Contracts\GscReadClient;
use App\Modules\Traffic\Application\Exceptions\GscReadException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpGscReadClient implements GscReadClient
{
    private ?string $accessToken = null;

    public function fetchQueryPageDailyMetrics(string $siteProperty, string $dateFrom, string $dateTo): iterable
    {
        $siteProperty = trim($siteProperty);
        if ($siteProperty === '') {
            throw new GscReadException('invalid_site_property', 'GSC site property is required.');
        }

        $rowLimit = max(1, min(25000, (int) config('services.google_search_console.row_limit', 25000)));
        $startRow = 0;
        do {
            $response = $this->client()->post(
                rtrim((string) config('services.google_search_console.base_url', 'https://www.googleapis.com/webmasters/v3'), '/')
                    .'/sites/'.rawurlencode($siteProperty).'/searchAnalytics/query',
                [
                    'startDate' => $dateFrom,
                    'endDate' => $dateTo,
                    'dimensions' => ['query', 'page', 'date', 'country', 'device'],
                    'rowLimit' => $rowLimit,
                    'startRow' => $startRow,
                ],
            );

            if (! $response->successful()) {
                throw new GscReadException('provider_error', 'GSC read request failed with HTTP '.$response->status().'.');
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new GscReadException('provider_invalid_response', 'GSC returned invalid JSON.');
            }
            $rows = $payload['rows'] ?? [];
            if (! is_array($rows)) {
                throw new GscReadException('provider_invalid_response', 'GSC rows payload is invalid.');
            }

            $count = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keys = is_array($row['keys'] ?? null) ? array_values($row['keys']) : [];
                if (count($keys) !== 5) {
                    throw new GscReadException('provider_invalid_response', 'GSC dimension keys are incomplete.');
                }
                $count++;
                yield [
                    'query' => trim((string) $keys[0]),
                    'page' => trim((string) $keys[1]),
                    'metric_date' => trim((string) $keys[2]),
                    'country' => strtolower(trim((string) $keys[3])),
                    'device' => strtolower(trim((string) $keys[4])),
                    'clicks' => $this->nonNegativeInt($row['clicks'] ?? null),
                    'impressions' => $this->nonNegativeInt($row['impressions'] ?? null),
                    'ctr' => $this->boundedFloat($row['ctr'] ?? null, 0.0, 1.0, 'ctr'),
                    'average_position' => $this->boundedFloat($row['position'] ?? null, 0.0, null, 'position'),
                ];
            }

            $startRow += $count;
        } while ($count === $rowLimit);
    }

    private function client(): PendingRequest
    {
        $config = $this->credentials();

        return Http::withToken($this->token())
            ->acceptJson()->asJson()->timeout($config['timeout']);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        $config = $this->credentials();
        $response = Http::asForm()->timeout($config['timeout'])->post(
            (string) config('services.google_search_console.oauth_url', 'https://oauth2.googleapis.com/token'),
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        );
        if (! $response->successful()) {
            throw new GscReadException('authentication_failed', 'GSC OAuth token refresh failed with HTTP '.$response->status().'.');
        }
        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new GscReadException('authentication_failed', 'GSC OAuth response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    /** @return array{client_id:string,client_secret:string,refresh_token:string,timeout:int} */
    private function credentials(): array
    {
        $config = [
            'client_id' => trim((string) config('services.google_search_console.client_id', '')),
            'client_secret' => trim((string) config('services.google_search_console.client_secret', '')),
            'refresh_token' => trim((string) config('services.google_search_console.refresh_token', '')),
            'timeout' => max(1, (int) config('services.google_search_console.timeout', 20)),
        ];
        foreach (['client_id', 'client_secret', 'refresh_token'] as $required) {
            if ($config[$required] === '') {
                throw new GscReadException('credentials_missing', 'GSC OAuth credentials are not configured.');
            }
        }

        return $config;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value || (float) $value > PHP_INT_MAX) {
            throw new GscReadException('provider_invalid_response', 'GSC returned an invalid integer metric.');
        }

        return (int) $value;
    }

    private function boundedFloat(mixed $value, float $min, ?float $max, string $field): float
    {
        if (! is_numeric($value)) {
            throw new GscReadException('provider_invalid_response', 'GSC returned invalid '.$field.'.');
        }
        $value = (float) $value;
        if ($value < $min || ($max !== null && $value > $max)) {
            throw new GscReadException('provider_invalid_response', 'GSC returned out-of-range '.$field.'.');
        }

        return $value;
    }
}
