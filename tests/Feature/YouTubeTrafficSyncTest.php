<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\YouTubeReadClient;
use App\Modules\Traffic\Application\YouTubeTrafficSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class YouTubeTrafficSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_youtube_sync_is_dry_run_by_default_apply_is_idempotent_and_uses_native_metrics(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->app->instance(YouTubeReadClient::class, new class implements YouTubeReadClient {
            public function fetchChannel(string $channelId): array
            {
                return [
                    'external_account_id' => $channelId,
                    'name' => 'Yibiyuan Irrigation',
                    'currency_code' => null,
                    'account_timezone' => null,
                ];
            }

            public function fetchDailyMetrics(string $channelId, string $dateFrom, string $dateTo): iterable
            {
                yield [
                    'metric_date' => '2026-09-17',
                    'scope' => 'account',
                    'external_object_id' => '',
                    'object_name' => 'Yibiyuan Irrigation',
                    'impressions' => 4000,
                    'reach' => null,
                    'engagements' => null,
                    'link_clicks' => null,
                    'video_views' => 800,
                    'watch_time_seconds' => 18000,
                    'followers' => null,
                    'followers_delta' => 6,
                    'spend_micros' => null,
                    'provider_metrics' => ['impressions_ctr' => 0.0475],
                ];

                yield [
                    'metric_date' => '2026-09-17',
                    'scope' => 'content',
                    'external_object_id' => 'k1l8IRfDx4s',
                    'object_name' => 'Irrigation Equipment Manufacturer',
                    'impressions' => 2200,
                    'reach' => null,
                    'engagements' => null,
                    'link_clicks' => null,
                    'video_views' => 500,
                    'watch_time_seconds' => 12000,
                    'followers' => null,
                    'followers_delta' => 4,
                    'spend_micros' => null,
                    'provider_metrics' => ['impressions_ctr' => 0.052],
                ];
            }
        });

        $service = app(YouTubeTrafficSyncService::class);
        $channel = 'UCCdp_oR9MW_uPYOHf1qt0Qg';

        $dry = $service->sync('yby-irrigation', $channel, '2026-09-17', '2026-09-17');
        $this->assertSame('dry_run', $dry['mode']);
        $this->assertSame(2, $dry['rows_seen']);
        $this->assertSame(1, $dry['account_rows']);
        $this->assertSame(1, $dry['content_rows']);
        $this->assertSame([], $dry['blocked']);
        $this->assertDatabaseCount('traffic_source_accounts', 0);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 0);

        $service->sync('yby-irrigation', $channel, '2026-09-17', '2026-09-17', 'apply');
        $service->sync('yby-irrigation', $channel, '2026-09-17', '2026-09-17', 'apply');

        $this->assertDatabaseCount('traffic_source_accounts', 1);
        $this->assertDatabaseHas('traffic_source_accounts', [
            'provider' => 'youtube',
            'external_account_id' => 'youtube:'.$channel,
            'name' => 'Yibiyuan Irrigation',
        ]);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 2);
        $this->assertDatabaseHas('traffic_native_daily_metrics', [
            'scope' => 'account',
            'video_views' => 800,
            'watch_time_seconds' => 18000,
            'followers_delta' => 6,
        ]);
        $this->assertDatabaseHas('traffic_native_daily_metrics', [
            'scope' => 'content',
            'external_object_id' => 'k1l8IRfDx4s',
            'video_views' => 500,
            'watch_time_seconds' => 12000,
        ]);
    }

    public function test_youtube_sync_fails_closed_when_connector_is_unconfigured(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $result = app(YouTubeTrafficSyncService::class)->sync(
            'yby-irrigation',
            'UCCdp_oR9MW_uPYOHf1qt0Qg',
            '2026-09-17',
            '2026-09-17',
        );

        $this->assertSame(['credentials_missing'], $result['blocked']);
        $this->assertDatabaseCount('traffic_source_accounts', 0);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 0);
    }

    public function test_youtube_sync_rejects_invalid_channel_id(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $result = app(YouTubeTrafficSyncService::class)
            ->sync('yby-irrigation', '@ybyirrigation', '2026-09-17', '2026-09-17');

        $this->assertSame(['invalid_input'], $result['blocked']);
    }
}
