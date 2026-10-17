<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Ga4TrafficDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_ga4_detail_route_renders_real_date_scoped_ga4_data(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        foreach ([
            ['scope' => 'site', 'dimension_value' => '', 'sessions' => 100, 'engaged_sessions' => 60, 'key_events' => 2.0, 'event_count' => 0],
            ['scope' => 'channel', 'dimension_value' => 'Organic Search', 'sessions' => 70, 'engaged_sessions' => 45, 'key_events' => 1.0, 'event_count' => 0],
            ['scope' => 'channel', 'dimension_value' => 'Direct', 'sessions' => 30, 'engaged_sessions' => 15, 'key_events' => 1.0, 'event_count' => 0],
            ['scope' => 'landing_page', 'dimension_value' => '/lp/irrigation-wholesale/', 'sessions' => 55, 'engaged_sessions' => 40, 'key_events' => 2.0, 'event_count' => 0],
            ['scope' => 'event', 'dimension_value' => 'generate_lead', 'sessions' => 3, 'engaged_sessions' => 0, 'key_events' => 0.0, 'event_count' => 3],
            ['scope' => 'event', 'dimension_value' => 'page_view', 'sessions' => 95, 'engaged_sessions' => 0, 'key_events' => 0.0, 'event_count' => 120],
        ] as $row) {
            TrafficGa4DailyMetric::create([
                'business_project_id' => $project->id,
                'metric_date' => '2026-09-17',
                'total_users' => 0,
                'new_users' => 0,
                ...$row,
            ]);
        }

        $this->actingAs($this->userWith('marketing.view'))
            ->get('/projects/yby-irrigation/traffic/ga4?date_from=2026-09-17&date_to=2026-09-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Traffic/Ga4/Index')
                ->where('project.key', 'yby-irrigation')
                ->where('summary.sessions', 100)
                ->where('summary.engaged_sessions', 60)
                ->where('summary.engagement_rate', 60)
                ->where('summary.key_events', 2)
                ->where('summary.business_conversion_events', 3)
                ->where('summary.days_with_data', 1)
                ->where('traffic_mix.0.label', 'Organic Search')
                ->where('traffic_mix.0.sessions', 70)
                ->where('traffic_mix.0.share', 70)
                ->where('landing_pages.0.path', '/lp/irrigation-wholesale/')
                ->where('landing_pages.0.sessions', 55)
                ->where('events.0.event_name', 'page_view')
                ->where('events.0.event_count', 120)
                ->where('contract.source', 'ga4')
                ->where('contract.revenue_state', 'excluded'));
    }

    public function test_ga4_detail_route_requires_marketing_view(): void
    {
        BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWith('dashboard.view', 'ga4-detail-no-marketing'))
            ->get('/projects/yby-irrigation/traffic/ga4')
            ->assertForbidden();
    }

    private function userWith(string $permissionKey, string $roleKey = 'ga4-detail-viewer'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        $permission = Permission::firstOrCreate(['key' => $permissionKey], ['label' => $permissionKey]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
