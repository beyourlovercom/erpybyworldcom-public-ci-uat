<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\Ga4ReadClient;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Ga4TrafficSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_ga4_sync_is_dry_run_by_default_apply_is_idempotent_and_overview_becomes_available(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->app->instance(Ga4ReadClient::class, new class implements Ga4ReadClient {
            public function fetchDailyMetrics(string $propertyId, string $dateFrom, string $dateTo, string $scope): iterable
            {
                return match ($scope) {
                    'site' => [[
                        'metric_date' => '2026-09-17', 'scope' => 'site', 'dimension_value' => '',
                        'sessions' => 100, 'total_users' => 80, 'new_users' => 60,
                        'engaged_sessions' => 70, 'key_events' => 12.0,
                    ]],
                    'channel' => [
                        [
                            'metric_date' => '2026-09-17', 'scope' => 'channel', 'dimension_value' => 'Organic Search',
                            'sessions' => 60, 'total_users' => 50, 'new_users' => 40,
                            'engaged_sessions' => 45, 'key_events' => 7.0,
                        ],
                        [
                            'metric_date' => '2026-09-17', 'scope' => 'channel', 'dimension_value' => 'Paid Search',
                            'sessions' => 40, 'total_users' => 30, 'new_users' => 20,
                            'engaged_sessions' => 25, 'key_events' => 5.0,
                        ],
                    ],
                    'landing_page' => [[
                        'metric_date' => '2026-09-17', 'scope' => 'landing_page', 'dimension_value' => '/lp/irrigation-wholesale/',
                        'sessions' => 35, 'total_users' => 30, 'new_users' => 20,
                        'engaged_sessions' => 24, 'key_events' => 4.0,
                    ]],
                    'event' => [
                        [
                            'metric_date' => '2026-09-17', 'scope' => 'event', 'dimension_value' => 'generate_lead',
                            'sessions' => 3, 'total_users' => 0, 'new_users' => 0,
                            'engaged_sessions' => 0, 'key_events' => 0.0, 'event_count' => 3,
                        ],
                        [
                            'metric_date' => '2026-09-17', 'scope' => 'event', 'dimension_value' => 'click_whatsapp',
                            'sessions' => 4, 'total_users' => 0, 'new_users' => 0,
                            'engaged_sessions' => 0, 'key_events' => 0.0, 'event_count' => 5,
                        ],
                        [
                            'metric_date' => '2026-09-17', 'scope' => 'event', 'dimension_value' => 'page_view',
                            'sessions' => 80, 'total_users' => 0, 'new_users' => 0,
                            'engaged_sessions' => 0, 'key_events' => 0.0, 'event_count' => 100,
                        ],
                    ],
                    default => [],
                };
            }
        });

        $this->artisan('traffic:ga4:sync', [
            '--project' => 'yby-irrigation',
            '--property' => '123456789',
            '--from' => '2026-09-17',
            '--to' => '2026-09-17',
        ])->assertSuccessful();

        $this->assertDatabaseCount('traffic_ga4_daily_metrics', 0);
        $this->assertDatabaseMissing('traffic_source_accounts', ['provider' => 'ga4']);

        $this->artisan('traffic:ga4:sync', [
            '--project' => 'yby-irrigation',
            '--property' => '123456789',
            '--from' => '2026-09-17',
            '--to' => '2026-09-17',
            '--apply' => true,
        ])->assertSuccessful();

        $this->artisan('traffic:ga4:sync', [
            '--project' => 'yby-irrigation',
            '--property' => '123456789',
            '--from' => '2026-09-17',
            '--to' => '2026-09-17',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('traffic_ga4_daily_metrics', 7);
        $this->assertDatabaseHas('traffic_source_accounts', [
            'provider' => 'ga4',
            'external_account_id' => '123456789',
        ]);
        $this->assertDatabaseHas('traffic_ga4_daily_metrics', [
            'scope' => 'site',
            'sessions' => 100,
            'engaged_sessions' => 70,
        ]);

        $this->actingAs($this->userWithMarketingView())
            ->get('/projects/yby-irrigation/traffic?date_from=2026-09-17&date_to=2026-09-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Traffic/Overview')
                ->where('summary.website_sessions', 100)
                ->where('summary.engaged_sessions', 70)
                ->where('summary.business_conversion_events', 8)
                ->where('channels.0.key', 'website')
                ->where('channels.0.connected', true)
                ->where('channels.0.sessions', 100)
                ->where('channels.0.engaged_sessions', 70)
                ->where('channels.0.business_conversion_events', 8)
                ->has('channels.0.business_event_breakdown', 2)
                ->has('traffic_mix', 2)
                ->where('traffic_mix.0.label', 'Organic Search')
                ->where('traffic_mix.0.value', 60)
                ->where('contract.website_metrics_state', 'available')
                ->where('contract.business_conversion_events.0', 'generate_lead')
                ->where('contract.business_conversion_events.1', 'click_whatsapp'));
    }

    public function test_real_client_without_credentials_fails_closed(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        config()->set('services.google_analytics.client_id', '');
        config()->set('services.google_analytics.client_secret', '');
        config()->set('services.google_analytics.refresh_token', '');

        $this->artisan('traffic:ga4:sync', [
            '--project' => 'yby-irrigation',
            '--property' => '123456789',
            '--from' => '2026-09-17',
            '--to' => '2026-09-17',
        ])->assertFailed();

        $this->assertDatabaseCount('traffic_ga4_daily_metrics', 0);
    }

    private function userWithMarketingView(): User
    {
        $role = Role::create(['key' => 'ga4-viewer', 'label' => 'GA4 Viewer']);
        $permission = Permission::firstOrCreate(['key' => 'marketing.view'], ['label' => 'marketing.view']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
