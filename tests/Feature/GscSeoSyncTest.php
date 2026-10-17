<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use App\Modules\Traffic\Application\Contracts\GscReadClient;
use App\Modules\Traffic\Application\GscSeoSyncService;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GscSeoSyncTest extends TestCase
{
    use RefreshDatabase;

    private BusinessProject $project;

    private BusinessProjectSite $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = BusinessProject::create([
            'project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active',
        ]);
        $this->site = BusinessProjectSite::create([
            'business_project_id' => $this->project->id,
            'site_key' => 'ybyirrigation-com',
            'domain_normalized' => 'ybyirrigation.com',
            'base_url' => 'https://ybyirrigation.com',
            'status' => 'active', 'is_primary' => true,
        ]);
        SeoKeyword::create([
            'business_project_id' => $this->project->id,
            'keyword' => 'drip irrigation system',
            'normalized_keyword' => 'drip irrigation system',
            'normalized_hash' => hash('sha256', 'drip irrigation system'),
            'status' => 'active',
        ]);
    }

    public function test_dry_run_summarizes_query_page_rows_without_writes(): void
    {
        $fake = FakeGscReadClient::fixture();
        $result = $this->service($fake)->sync(
            'yby-irrigation', 'ybyirrigation-com', 'sc-domain:ybyirrigation.com',
            '2026-09-15', '2026-09-16', 'dry_run',
        );

        $this->assertSame([], $result['blocked']);
        $this->assertSame(2, $result['query_rows_seen']);
        $this->assertSame(2, $result['keywords_seen']);
        $this->assertSame(1, $result['keywords_missing']);
        $this->assertSame(0, $result['keywords_created']);
        $this->assertDatabaseCount('seo_keywords', 1);
        $this->assertDatabaseCount('seo_gsc_daily_metrics', 0);
    }

    public function test_apply_is_idempotent_and_only_adds_missing_canonical_seo_keyword(): void
    {
        $fake = FakeGscReadClient::fixture();
        $service = $this->service($fake);

        $first = $service->sync(
            'yby-irrigation', 'ybyirrigation-com', 'sc-domain:ybyirrigation.com',
            '2026-09-15', '2026-09-16', 'apply',
        );
        $this->assertSame([], $first['blocked']);
        $this->assertSame(1, $first['keywords_created']);
        $this->assertSame(2, $first['daily_rows_applied']);
        $this->assertDatabaseCount('seo_keywords', 2);
        $this->assertDatabaseCount('seo_gsc_daily_metrics', 2);
        $this->assertDatabaseHas('seo_keywords', ['normalized_keyword' => 'irrigation system supplier']);
        $this->assertDatabaseHas('seo_gsc_daily_metrics', [
            'business_project_site_id' => $this->site->id,
            'metric_date' => '2026-09-16',
            'page_url' => 'https://ybyirrigation.com/lp/irrigation-wholesale/',
            'country' => 'tza', 'device' => 'mobile', 'clicks' => 5, 'impressions' => 70,
        ]);

        $second = $service->sync(
            'yby-irrigation', 'ybyirrigation-com', 'sc-domain:ybyirrigation.com',
            '2026-09-15', '2026-09-16', 'apply',
        );
        $this->assertSame(0, $second['keywords_created']);
        $this->assertDatabaseCount('seo_keywords', 2);
        $this->assertDatabaseCount('seo_gsc_daily_metrics', 2);
        $this->assertDatabaseCount('cases', 0);
    }

    public function test_oversize_gsc_rows_are_skipped_without_blocking_sync(): void
    {
        $rows = FakeGscReadClient::fixture()->rows;
        $rows[] = [
            'query' => str_repeat('x', 501), 'page' => 'https://ybyirrigation.com/oversize/',
            'metric_date' => '2026-09-16', 'country' => 'usa', 'device' => 'desktop',
            'clicks' => 0, 'impressions' => 1, 'ctr' => 0.0, 'average_position' => 88.0,
        ];
        $result = $this->service(new FakeGscReadClient($rows))->sync(
            'yby-irrigation', 'ybyirrigation-com', 'sc-domain:yby-irrigation.com',
            '2026-09-15', '2026-09-16', 'dry_run',
        );
        $this->assertSame([], $result['blocked']);
        $this->assertSame(1, $result['query_rows_skipped_oversize']);
        $this->assertSame(2, $result['query_rows_seen']);
    }

    public function test_unknown_site_blocks_before_provider_read(): void
    {
        $fake = FakeGscReadClient::fixture();
        $result = $this->service($fake)->sync(
            'yby-irrigation', 'missing-site', 'sc-domain:ybyirrigation.com',
            '2026-09-15', '2026-09-16', 'apply',
        );
        $this->assertSame(['site_not_found'], $result['blocked']);
        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('seo_gsc_daily_metrics', 0);
    }

    private function service(FakeGscReadClient $fake): GscSeoSyncService
    {
        $this->app->instance(GscReadClient::class, $fake);

        return $this->app->make(GscSeoSyncService::class);
    }
}

final class FakeGscReadClient implements GscReadClient
{
    public int $calls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $rows;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public static function fixture(): self
    {
        return new self([
            [
                'query' => 'Drip Irrigation System',
                'page' => 'https://ybyirrigation.com/drip-irrigation-system/',
                'metric_date' => '2026-09-15', 'country' => 'usa', 'device' => 'desktop',
                'clicks' => 3, 'impressions' => 120, 'ctr' => 0.025, 'average_position' => 14.2,
            ],
            [
                'query' => 'Irrigation System Supplier',
                'page' => 'https://ybyirrigation.com/lp/irrigation-wholesale/',
                'metric_date' => '2026-09-16', 'country' => 'tza', 'device' => 'mobile',
                'clicks' => 5, 'impressions' => 70, 'ctr' => 0.07142857, 'average_position' => 9.8,
            ],
        ]);
    }

    public function fetchQueryPageDailyMetrics(string $siteProperty, string $dateFrom, string $dateTo): iterable
    {
        $this->calls++;

        return $this->rows;
    }
}
