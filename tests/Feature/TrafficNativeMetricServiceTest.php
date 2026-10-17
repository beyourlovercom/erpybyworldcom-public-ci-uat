<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\TrafficNativeMetricService;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class TrafficNativeMetricServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_social_metrics_preserve_null_vs_zero_and_are_idempotent(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $account = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'meta',
            'external_account_id' => 'page-1',
            'name' => 'YBY Irrigation Facebook',
            'status' => 'active',
        ]);

        $service = app(TrafficNativeMetricService::class);

        $service->upsert($account, [
            'metric_date' => '2026-09-18',
            'scope' => 'account',
            'impressions' => 1200,
            'reach' => 900,
            'engagements' => 0,
            'link_clicks' => 23,
            'video_views' => null,
            'watch_time_seconds' => null,
            'followers' => 1500,
            'followers_delta' => -2,
            'provider_metrics' => ['page_post_engagements' => 88],
        ]);

        $service->upsert($account, [
            'metric_date' => '2026-09-18',
            'scope' => 'account',
            'impressions' => 1250,
            'reach' => 930,
            'engagements' => 0,
            'link_clicks' => 25,
            'video_views' => null,
            'watch_time_seconds' => null,
            'followers' => 1502,
            'followers_delta' => 2,
            'provider_metrics' => ['page_post_engagements' => 91],
        ]);

        $this->assertDatabaseCount('traffic_native_daily_metrics', 1);
        $row = $account->nativeDailyMetrics()->firstOrFail();

        $this->assertSame(1250, $row->impressions);
        $this->assertSame(0, $row->engagements);
        $this->assertNull($row->video_views);
        $this->assertNull($row->watch_time_seconds);
        $this->assertSame(2, $row->followers_delta);
        $this->assertSame(['page_post_engagements' => 91], $row->provider_metrics);
    }

    public function test_native_metric_contract_rejects_unsupported_provider_scope_and_negative_counts(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $ads = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'google_ads',
            'external_account_id' => 'ads-1',
            'status' => 'active',
        ]);

        $service = app(TrafficNativeMetricService::class);

        try {
            $service->upsert($ads, ['metric_date' => '2026-09-18']);
            $this->fail('Google Ads must not use the native social metric contract.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unsupported native traffic provider.', $e->getMessage());
        }

        $meta = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'meta',
            'external_account_id' => 'page-2',
            'status' => 'active',
        ]);

        foreach ([
            ['metric_date' => '2026-09-18', 'scope' => 'story'],
            ['metric_date' => '2026-09-18', 'scope' => 'account', 'reach' => -1],
        ] as $payload) {
            try {
                $service->upsert($meta, $payload);
                $this->fail('Invalid native traffic metric payload must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('traffic_native_daily_metrics', 0);
    }
}
