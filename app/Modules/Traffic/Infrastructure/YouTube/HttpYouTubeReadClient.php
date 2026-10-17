<?php

namespace App\Modules\Traffic\Infrastructure\YouTube;

use App\Modules\Traffic\Application\Contracts\YouTubeReadClient;
use App\Modules\Traffic\Application\Exceptions\YouTubeReadException;
use Illuminate\Support\Facades\Http;

final class HttpYouTubeReadClient implements YouTubeReadClient
{
    private ?string $accessToken = null;

    public function fetchChannel(string $channelId): array
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout($this->timeout())
            ->get(
                rtrim((string) config('services.youtube.data_base_url', 'https://www.googleapis.com/youtube/v3'), '/').'/channels',
                ['part' => 'snippet', 'id' => $channelId],
            );

        if (! $response->successful()) {
            throw new YouTubeReadException('provider_error', 'YouTube Data API request failed with HTTP '.$response->status().'.');
        }

        $item = $response->json('items.0');
        if (! is_array($item)) {
            throw new YouTubeReadException('account_not_found', 'YouTube channel was not returned.');
        }
        if ((string) ($item['id'] ?? '') !== $channelId) {
            throw new YouTubeReadException('source_account_mismatch', 'YouTube returned a different channel.');
        }

        return [
            'external_account_id' => $channelId,
            'name' => $this->nullableString($item['snippet']['title'] ?? null),
            'currency_code' => null,
            'account_timezone' => null,
        ];
    }

    public function fetchDailyMetrics(string $channelId, string $dateFrom, string $dateTo): iterable
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout($this->timeout())
            ->get(
                rtrim((string) config('services.youtube.analytics_base_url', 'https://youtubeanalytics.googleapis.com/v2'), '/').'/reports',
                [
                    'ids' => 'channel=='.$channelId,
                    'startDate' => $dateFrom,
                    'endDate' => $dateTo,
                    'metrics' => 'views,estimatedMinutesWatched,subscribersGained,subscribersLost',
                    'dimensions' => 'day',
                    'sort' => 'day',
                ],
            );

        if (! $response->successful()) {
            throw new YouTubeReadException('provider_error', 'YouTube Analytics API request failed with HTTP '.$response->status().'.');
        }

        $headers = $response->json('columnHeaders');
        $rows = $response->json('rows') ?? [];

        if (! is_array($headers) || ! is_array($rows)) {
            throw new YouTubeReadException('provider_invalid_response', 'YouTube Analytics returned invalid report data.');
        }

        $indexes = [];
        foreach ($headers as $index => $header) {
            if (is_array($header) && isset($header['name'])) {
                $indexes[(string) $header['name']] = (int) $index;
            }
        }

        foreach (['day', 'views', 'estimatedMinutesWatched', 'subscribersGained', 'subscribersLost'] as $required) {
            if (! array_key_exists($required, $indexes)) {
                throw new YouTubeReadException('provider_invalid_response', 'YouTube Analytics report is missing '.$required.'.');
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $gained = $this->nonNegativeInt($row[$indexes['subscribersGained']] ?? null, 'subscribersGained');
            $lost = $this->nonNegativeInt($row[$indexes['subscribersLost']] ?? null, 'subscribersLost');
            $minutes = $this->nonNegativeNumeric($row[$indexes['estimatedMinutesWatched']] ?? null, 'estimatedMinutesWatched');

            yield [
                'metric_date' => (string) ($row[$indexes['day']] ?? ''),
                'scope' => 'account',
                'external_object_id' => '',
                'object_name' => null,
                'impressions' => null,
                'reach' => null,
                'engagements' => null,
                'link_clicks' => null,
                'video_views' => $this->nonNegativeInt($row[$indexes['views']] ?? null, 'views'),
                'watch_time_seconds' => (int) round($minutes * 60),
                'followers' => null,
                'followers_delta' => $gained - $lost,
                'spend_micros' => null,
                'provider_metrics' => [
                    'subscribers_gained' => $gained,
                    'subscribers_lost' => $lost,
                ],
            ];
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $injected = trim((string) config('services.youtube.access_token', ''));
        if ($injected !== '') {
            return $this->accessToken = $injected;
        }

        $tokenFile = trim((string) config('services.youtube.access_token_file', ''));
        if ($tokenFile !== '') {
            if (! is_file($tokenFile) || ! is_readable($tokenFile)) {
                throw new YouTubeReadException('credentials_missing', 'YouTube access token file is not readable.');
            }
            $fileToken = trim((string) file_get_contents($tokenFile));
            if ($fileToken === '') {
                throw new YouTubeReadException('credentials_missing', 'YouTube access token file is empty.');
            }

            return $this->accessToken = $fileToken;
        }

        $clientId = trim((string) config('services.youtube.client_id', ''));
        $clientSecret = trim((string) config('services.youtube.client_secret', ''));
        $refreshToken = trim((string) config('services.youtube.refresh_token', ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new YouTubeReadException('credentials_missing', 'YouTube OAuth credentials are not configured.');
        }

        $response = Http::asForm()->timeout($this->timeout())->post(
            (string) config('services.youtube.oauth_url', 'https://oauth2.googleapis.com/token'),
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        );

        if (! $response->successful()) {
            throw new YouTubeReadException('authentication_failed', 'YouTube OAuth token refresh failed with HTTP '.$response->status().'.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new YouTubeReadException('authentication_failed', 'YouTube OAuth response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.youtube.timeout', 20));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            throw new YouTubeReadException('provider_invalid_response', 'YouTube returned invalid '.$field.'.');
        }

        return (int) $value;
    }

    private function nonNegativeNumeric(mixed $value, string $field): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new YouTubeReadException('provider_invalid_response', 'YouTube returned invalid '.$field.'.');
        }

        return (float) $value;
    }
}
