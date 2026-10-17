<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\MetaReadClient;
use App\Modules\Traffic\Application\MetaTrafficSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetaTrafficSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_page_sync_is_dry_run_by_default_apply_is_idempotent_and_uses_native_metrics(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->app->instance(MetaReadClient::class, new class implements MetaReadClient {
            public function fetchAccount(string $sourceType, string $externalAccountId): array
            {
                return [
                    'external_account_id' => $externalAccountId,
                    'name' => 'YBY Irrigation Facebook',
                    'currency_code' => null,
                    'account_timezone' => 'Asia/Shanghai',
                ];
            }

            public function fetchDailyMetrics(string $sourceType, string $externalAccountId, string $dateFrom, string $dateTo): iterable
            {
                yield [
                    'metric_date' => '2026-09-17',
                    'scope' => 'account',
                    'external_object_id' => '',
                    'object_name' => 'YBY Irrigation Facebook',
                    'impressions' => 1200,
                    'reach' => 900,
                    'engagements' => 110,
                    'link_clicks' => 25,
                    'video_views' => null,
                    'watch_time_seconds' => null,
                    'followers' => 1500,
                    'followers_delta' => 3,
                    'spend_micros' => null,
                    'provider_metrics' => ['page_post_engagements' => 110],
                ];

                yield [
                    'metric_date' => '2026-09-17',
                    'scope' => 'content',
                    'external_object_id' => 'post-123',
                    'object_name' => 'Drip irrigation demo',
                    'impressions' => 500,
                    'reach' => 420,
                    'engagements' => 80,
                    'link_clicks' => 12,
                    'video_views' => 300,
                    'watch_time_seconds' => 7200,
                    'followers' => null,
                    'followers_delta' => null,
                    'spend_micros' => null,
                    'provider_metrics' => [],
                ];
            }
        });

        $service = app(MetaTrafficSyncService::class);

        $dry = $service->sync('yby-irrigation', 'page', '12345', '2026-09-17', '2026-09-17');
        $this->assertSame('dry_run', $dry['mode']);
        $this->assertSame(2, $dry['rows_seen']);
        $this->assertSame(1, $dry['account_rows']);
        $this->assertSame(1, $dry['content_rows']);
        $this->assertSame([], $dry['blocked']);
        $this->assertDatabaseCount('traffic_source_accounts', 0);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 0);

        $first = $service->sync('yby-irrigation', 'page', '12345', '2026-09-17', '2026-09-17', 'apply');
        $second = $service->sync('yby-irrigation', 'page', '12345', '2026-09-17', '2026-09-17', 'apply');

        $this->assertSame(2, $first['rows_applied']);
        $this->assertSame(2, $second['rows_applied']);
        $this->assertDatabaseCount('traffic_source_accounts', 1);
        $this->assertDatabaseHas('traffic_source_accounts', [
            'provider' => 'meta',
            'external_account_id' => 'page:12345',
            'name' => 'YBY Irrigation Facebook',
        ]);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 2);
        $this->assertDatabaseHas('traffic_native_daily_metrics', [
            'scope' => 'account',
            'external_object_id' => '',
            'impressions' => 1200,
            'reach' => 900,
            'link_clicks' => 25,
        ]);
        $this->assertDatabaseHas('traffic_native_daily_metrics', [
            'scope' => 'content',
            'external_object_id' => 'post-123',
            'video_views' => 300,
            'watch_time_seconds' => 7200,
        ]);
    }

    public function test_meta_sync_fails_closed_when_connector_is_unconfigured(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $result = app(MetaTrafficSyncService::class)
            ->sync('yby-irrigation', 'page', '12345', '2026-09-17', '2026-09-17');

        $this->assertSame(['credentials_missing'], $result['blocked']);
        $this->assertDatabaseCount('traffic_source_accounts', 0);
        $this->assertDatabaseCount('traffic_native_daily_metrics', 0);
    }

    public function test_meta_sync_rejects_invalid_source_and_out_of_range_rows(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $service = app(MetaTrafficSyncService::class);
        $invalidSource = $service->sync('yby-irrigation', 'instagram', '12345', '2026-09-17', '2026-09-17');

        $this->assertSame(['invalid_input'], $invalidSource['blocked']);

        $this->app->instance(MetaReadClient::class, new class implements MetaReadClient {
            public function fetchAccount(string $sourceType, string $externalAccountId): array
            {
                return [
                    'external_account_id' => $externalAccountId,
                    'name' => 'Meta Test',
                    'currency_code' => null,
                    'account_timezone' => null,
                ];
            }

            public function fetchDailyMetrics(string $sourceType, string $externalAccountId, string $dateFrom, string $dateTo): iterable
            {
                yield [
                    'metric_date' => '2026-09-18',
                    'scope' => 'account',
                    'external_object_id' => '',
                ];
            }
        });

        $result = app(MetaTrafficSyncService::class)
            ->sync('yby-irrigation', 'page', '12345', '2026-09-17', '2026-09-17');

        $this->assertSame(['invalid_metric_row'], $result['blocked']);
        $this->assertDatabaseCount('traffic_source_accounts', 0);
    }
}
