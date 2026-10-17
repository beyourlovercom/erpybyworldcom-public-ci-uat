<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use App\Modules\Traffic\Infrastructure\Models\SeoKeywordEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SeoTrafficWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_marketing_viewer_sees_project_scoped_keyword_opportunities(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $keyword = SeoKeyword::create([            'business_project_id' => $project->id,
            'keyword' => 'drip irrigation system',
            'normalized_keyword' => 'drip irrigation system',
            'normalized_hash' => hash('sha256', 'drip irrigation system'),
            'topic_cluster' => 'Drip Irrigation System',
            'suggested_target_type' => 'Product/Category Page',
            'suggested_action' => 'PAGE1_P0',
            'opportunity_score' => 80,
            'status' => 'active',
        ]);
        SeoKeywordEvidence::create([
            'seo_keyword_id' => $keyword->id,
            'source_type' => 'google_ads',
            'source_key' => 'seed',
            'impressions' => 4034,
            'clicks' => 758,
            'conversions' => 10,
            'cost' => 1584.90,
            'currency_code' => 'CNY',
        ]);
        SeoKeywordEvidence::create([
            'seo_keyword_id' => $keyword->id,
            'source_type' => 'google_search_console',
            'source_key' => 'seed',
            'impressions' => 1172,
            'clicks' => 3,
            'average_position' => 37.19,
        ]);
        $response = $this->actingAs($this->userWithMarketingView())
            ->get('/projects/yby-irrigation/traffic/seo?action=PAGE1_P0&source=google_ads');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Traffic/Seo/Index')
            ->where('project.key', 'yby-irrigation')
            ->where('summary.keywords', 1)
            ->where('summary.evidence_rows', 2)
            ->where('summary.priority_opportunities', 1)
            ->where('action_counts.PAGE1_P0', 1)
            ->where('evidence_counts.google_ads', 1)
            ->where('evidence_counts.google_search_console', 1)
            ->where('filters.action', 'PAGE1_P0')
            ->where('filters.source', 'google_ads')
            ->has('keywords.data', 1)
            ->where('keywords.data.0.keyword', 'drip irrigation system')
            ->where('data_health.gsc_live_connected', false));
    }

    public function test_workspace_requires_marketing_view_permission(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $this->assertNotNull($project->id);
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/projects/yby-irrigation/traffic/seo')->assertForbidden();
    }

    private function userWithMarketingView(): User
    {
        $role = Role::create(['key' => 'seo-workspace-viewer', 'label' => 'seo-workspace-viewer']);
        $permission = Permission::firstOrCreate(['key' => 'marketing.view'], ['label' => 'marketing.view']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
