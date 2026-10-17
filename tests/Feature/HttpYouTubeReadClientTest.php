<?php

namespace Tests\Feature;

use App\Modules\Traffic\Application\Exceptions\YouTubeReadException;
use App\Modules\Traffic\Infrastructure\YouTube\HttpYouTubeReadClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpYouTubeReadClientTest extends TestCase
{
    public function test_http_client_maps_channel_and_daily_analytics_without_inventing_reach_metrics(): void
    {
        config()->set('services.youtube.access_token', 'test-token');

        Http::fake([
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'id' => 'UCCdp_oR9MW_uPYOHf1qt0Qg',
                    'snippet' => ['title' => 'Yibiyuan Irrigation'],
                ]],
            ], 200),
            'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
                'columnHeaders' => [
                    ['name' => 'day'],
                    ['name' => 'views'],
                    ['name' => 'estimatedMinutesWatched'],
                    ['name' => 'subscribersGained'],
                    ['name' => 'subscribersLost'],
                ],
                'rows' => [
                    ['2026-09-17', 125, 42.5, 4, 1],
                ],
            ], 200),
        ]);

        $client = app(HttpYouTubeReadClient::class);
        $channel = $client->fetchChannel('UCCdp_oR9MW_uPYOHf1qt0Qg');
        $rows = iterator_to_array($client->fetchDailyMetrics(
            'UCCdp_oR9MW_uPYOHf1qt0Qg',
            '2026-09-17',
            '2026-09-17',
        ));

        $this->assertSame('UCCdp_oR9MW_uPYOHf1qt0Qg', $channel['external_account_id']);
        $this->assertSame('Yibiyuan Irrigation', $channel['name']);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-17', $rows[0]['metric_date']);
        $this->assertSame(125, $rows[0]['video_views']);
        $this->assertSame(2550, $rows[0]['watch_time_seconds']);
        $this->assertSame(3, $rows[0]['followers_delta']);
        $this->assertNull($rows[0]['impressions']);
        $this->assertNull($rows[0]['reach']);
        $this->assertSame(4, $rows[0]['provider_metrics']['subscribers_gained']);
        $this->assertSame(1, $rows[0]['provider_metrics']['subscribers_lost']);
    }

    public function test_http_client_fails_closed_without_credentials(): void
    {
        config()->set('services.youtube.access_token', '');
        config()->set('services.youtube.access_token_file', '');
        config()->set('services.youtube.client_id', '');
        config()->set('services.youtube.client_secret', '');
        config()->set('services.youtube.refresh_token', '');

        $this->expectException(YouTubeReadException::class);
        $this->expectExceptionMessage('YouTube OAuth credentials are not configured.');

        app(HttpYouTubeReadClient::class)->fetchChannel('UCCdp_oR9MW_uPYOHf1qt0Qg');
    }
}
