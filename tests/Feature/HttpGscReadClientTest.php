<?php

namespace Tests\Feature;

use App\Modules\Traffic\Application\Contracts\GscReadClient;
use App\Modules\Traffic\Application\Exceptions\GscReadException;
use App\Modules\Traffic\Infrastructure\Gsc\HttpGscReadClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpGscReadClientTest extends TestCase
{
    public function test_service_provider_binds_real_gsc_client(): void
    {
        $this->assertInstanceOf(HttpGscReadClient::class, $this->app->make(GscReadClient::class));
    }

    public function test_missing_credentials_fail_before_http(): void
    {
        config()->set('services.google_search_console.client_id', '');
        config()->set('services.google_search_console.client_secret', '');
        config()->set('services.google_search_console.refresh_token', '');
        Http::fake();

        try {
            iterator_to_array((new HttpGscReadClient)->fetchQueryPageDailyMetrics(
                'sc-domain:ybyirrigation.com', '2026-09-15', '2026-09-16'
            ));
            $this->fail('Expected credentials_missing.');
        } catch (GscReadException $e) {
            $this->assertSame('credentials_missing', $e->status);
        }
        Http::assertNothingSent();
    }

    public function test_adapter_maps_query_page_dimensions_using_read_only_search_analytics(): void
    {
        config()->set('services.google_search_console.client_id', 'client-id');
        config()->set('services.google_search_console.client_secret', 'client-secret');
        config()->set('services.google_search_console.refresh_token', 'refresh-token');
        config()->set('services.google_search_console.row_limit', 25000);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'https://www.googleapis.com/webmasters/v3/*' => Http::response([
                'rows' => [[
                    'keys' => [
                        'irrigation system supplier',
                        'https://ybyirrigation.com/lp/irrigation-wholesale/',
                        '2026-09-16', 'tza', 'MOBILE',
                    ],
                    'clicks' => 5, 'impressions' => 70, 'ctr' => 0.07142857, 'position' => 9.8,
                ]],
            ], 200),
        ]);

        $rows = iterator_to_array((new HttpGscReadClient)->fetchQueryPageDailyMetrics(
            'sc-domain:ybyirrigation.com', '2026-09-15', '2026-09-16'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('irrigation system supplier', $rows[0]['query']);
        $this->assertSame('mobile', $rows[0]['device']);
        $this->assertSame(70, $rows[0]['impressions']);
        $this->assertSame(9.8, $rows[0]['average_position']);
        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/searchAnalytics/query')) {
                return true;
            }
            $body = $request->data();

            return ($body['dimensions'] ?? null) === ['query', 'page', 'date', 'country', 'device']
                && ($body['startDate'] ?? null) === '2026-09-15'
                && ($body['endDate'] ?? null) === '2026-09-16'
                && ! str_contains(strtolower(json_encode($body)), 'mutate');
        });
    }
}
