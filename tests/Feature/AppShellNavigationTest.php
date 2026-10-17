<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_guest_shell_access_redirects_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/sync')->assertRedirect('/login');
    }

    public function test_shell_navigation_permission_resolution_has_bounded_query_count(): void
    {
        Warehouse::query()->delete();
        Warehouse::query()->create(['slug' => 'de', 'code' => 'DE', 'name' => '德国仓', 'is_active' => true, 'priority' => 10]);

        $permissions = collect(config('erp_shell_navigation'))
            ->flatMap(function (array $item): array {
                $keys = array_values(array_filter([
                    $item['permission'] ?? null,
                    ...($item['permissions_any'] ?? []),
                    ...($item['permissions_all'] ?? []),
                ]));
                foreach ($item['children'] ?? [] as $child) {
                    $keys = [...$keys, ...array_values(array_filter([
                        $child['permission'] ?? null,
                        ...($child['permissions_any'] ?? []),
                        ...($child['permissions_all'] ?? []),
                    ]))];
                }

                return $keys;
            })
            ->unique()->values()->all();
        $user = $this->userWith($permissions, 'navigation-query-budget');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $navigation = app(ErpShellNavigation::class)->for($user);

        $this->assertNotEmpty($navigation);
        $this->assertLessThanOrEqual(4, $queryCount, 'Shell navigation must not query once per permission check.');
    }

    public function test_dashboard_renders_formal_shell_with_safe_shared_props(): void
    {

        $user = $this->userWith(['dashboard.view', 'sync.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->has('shellNavigation', 1)
            ->where('shellNavigation.0.key', 'dashboard')
            ->missing('shellNavigation.1'));
    }

    public function test_navigation_comes_from_formal_source_and_filters_unauthorized_modules(): void
    {

        $user = $this->userWith(['dashboard.view', 'logistics.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 1)
            ->where('shellNavigation.0.label', '仪表盘')
            ->missing('shellNavigation.1'));
        $this->actingAs($user)->get('/sync')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
    }

    public function test_yby_navigation_profile_hides_legacy_logistics_with_or_without_prototype_permission(): void
    {

        // YBY intentionally hides legacy Logistics navigation even when the caller
        // has legacy logistics and prototype permissions.
        $user = $this->userWith(['dashboard.view', 'logistics.view'], 'operator-without-prototype');
        $this->assertSame(
            ['dashboard'],
            array_column(app(ErpShellNavigation::class)->for($user), 'key')
        );

        $withPrototype = $this->userWith(
            ['dashboard.view', 'logistics.view', 'prototype.view'], 'operator-with-prototype'
        );
        $this->assertSame(
            ['dashboard'],
            array_column(app(ErpShellNavigation::class)->for($withPrototype), 'key')
        );
    }

    public function test_project_traffic_navigation_exposes_growth_subpages(): void
    {
        BusinessProject::query()->create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
        $user = $this->userWith(['dashboard.view', 'marketing.view'], 'traffic-navigation');

        $navigation = app(ErpShellNavigation::class)->for($user);
        $project = collect($navigation)->firstWhere('key', 'project-yby-irrigation');
        $this->assertNotNull($project);

        $overview = collect($project['children'])->firstWhere('key', 'project-yby-irrigation-overview');
        $this->assertNotNull($overview);
        $this->assertSame('老板看板', $overview['label']);
        $this->assertSame('/projects/yby-irrigation', $overview['path']);

        $traffic = collect($project['children'])->firstWhere('key', 'project-yby-irrigation-traffic');
        $this->assertNotNull($traffic);
        $this->assertSame('/projects/yby-irrigation/traffic', $traffic['path']);
        $this->assertSame([
            '/projects/yby-irrigation/traffic/ga4',
            '/projects/yby-irrigation/traffic/landing-pages',
            '/projects/yby-irrigation/traffic/seo',
        ], collect($traffic['children'])->pluck('path')->all());
        $this->assertSame([
            'GA4',
            'Landing Pages',
            'SEO Growth',
        ], collect($traffic['children'])->pluck('label')->all());
    }

    public function test_yby_navigation_hides_legacy_inventory_even_when_warehouse_route_is_authorized(): void
    {

        Warehouse::query()->delete();
        Warehouse::query()->create(['slug' => 'de', 'code' => 'DE', 'name' => '德国仓', 'is_active' => true, 'priority' => 10]);
        Warehouse::query()->create(['slug' => 'us-east', 'code' => 'USE', 'name' => '美东仓', 'is_active' => true, 'priority' => 20]);
        Warehouse::query()->create(['slug' => 'inactive', 'code' => 'OFF', 'name' => '停用仓', 'is_active' => false, 'priority' => 1]);

        $user = $this->userWith(['dashboard.view', 'inventory.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 1)
            ->where('shellNavigation.0.key', 'dashboard')
            ->missing('shellNavigation.1'));

        // The underlying warehouse route authorization is separate from
        // the YBY project-specific menu allowlist.
        $this->actingAs($user)->get('/inventory/de')->assertOk();
    }

    public function test_yby_profile_does_not_expose_legacy_inventory_from_packaging_permission(): void
    {

        $user = $this->userWith(['dashboard.view', 'inventory.packaging.submit'], 'factory-packaging-menu');
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 1)
            ->where('shellNavigation.0.key', 'dashboard')
            ->missing('shellNavigation.1'));
    }

    public function test_yby_profile_does_not_expose_legacy_accounting_navigation(): void
    {

        $user = $this->userWith(['dashboard.view', 'accounting.view', 'analysis.view', 'prototype.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 1)
            ->where('shellNavigation.0.key', 'dashboard')
            ->missing('shellNavigation.1'));
    }

    public function test_yby_customer_import_menu_and_sync_route_share_permission_contract(): void
    {

        $user = $this->userWith(
            ['dashboard.view', 'sync.view', 'customers.view', 'customers.import', 'system.permissions.manage'],
            'customer-import-admin'
        );

        // The YBY profile keeps Customers > Import, not the legacy Sync menu.
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 3)
            ->where('shellNavigation.0.key', 'dashboard')
            ->where('shellNavigation.1.key', 'customers')
            ->has('shellNavigation.1.children', 4)
            ->where('shellNavigation.1.children.2.key', 'customers-companies')
            ->where('shellNavigation.1.children.3.key', 'customers-import')
            ->where('shellNavigation.1.children.3.path', '/customers/import')
            ->where('shellNavigation.2.key', 'settings'));

        // Backend routes retain their independent permission contract.
        $this->actingAs($user)->get('/customers/import')->assertInertia(fn (Assert $page) => $page->component('Customers/Import'));
        $this->actingAs($user)->get('/sync/customers/import')->assertInertia(fn (Assert $page) => $page->component('Customers/Import'));
    }

    public function test_customer_import_navigation_requires_both_import_and_permission_admin_gates(): void
    {

        $withoutAdminGate = $this->userWith(
            ['dashboard.view', 'sync.view', 'customers.view', 'customers.import'],
            'customer-import-no-admin'
        );

        $this->actingAs($withoutAdminGate)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 2)
            ->where('shellNavigation.0.key', 'dashboard')
            ->where('shellNavigation.1.key', 'customers')
            ->has('shellNavigation.1.children', 3)
            ->where('shellNavigation.1.children.2.key', 'customers-companies'));

        $this->actingAs($withoutAdminGate)->get('/customers/import')->assertForbidden();
        $this->actingAs($withoutAdminGate)->get('/sync/customers/import')->assertForbidden();
    }

    public function test_sync_route_permission_does_not_reintroduce_filtered_yby_sync_navigation(): void
    {

        $user = $this->userWith(['sync.view']);
        $this->actingAs($user)->get('/sync')->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Index')
            ->has('shellNavigation', 0));
    }

    public function test_settings_fx_child_menu_is_implemented_with_settings_permission(): void
    {
        $settings = collect(config('erp_shell_navigation'))->firstWhere('key', 'settings');
        $fx = collect($settings['children'] ?? [])->firstWhere('key', 'settings-fx');
        $this->assertSame('汇率计算', $fx['label']);
        $this->assertSame('/settings/fx', $fx['path']);
        $this->assertSame('settings.fx', $fx['route']);
        $this->assertSame('system.permissions.manage', $fx['permission']);
        $this->assertSame('implemented', $fx['status']);
    }

    public function test_backup_child_menu_uses_backup_view_permission_and_canonical_route(): void
    {
        $user = $this->userWith(['dashboard.view', 'system.permissions.manage', 'backup.view'], 'backup-menu-user');
        $settings = collect(config('erp_shell_navigation'))->firstWhere('key', 'settings');
        $backup = collect($settings['children'] ?? [])->firstWhere('key', 'settings-backup');
        $this->assertSame('备份与迁移', $backup['label']);
        $this->assertSame('/settings/backup', $backup['path']);
        $this->assertSame('backup.index', $backup['route']);
        $this->assertSame('backup.view', $backup['permission']);
    }

    public function test_only_exact_legacy_root_path_collisions_fall_back_for_prototype_users(): void
    {
        $prototypeUser = $this->userWith(['prototype.view'], 'prototype-operator');
        $this->actingAs($prototypeUser)->get('/procurement')->assertForbidden();
        $this->actingAs($prototypeUser)->get('/purchase')->assertForbidden();
        // Guides is now a formal knowledge-base module and no longer falls back to prototype access.
        $this->actingAs($prototypeUser)->get('/guides')->assertForbidden();
        // /products now requires products.view (not prototype.view); prototype-only users are forbidden
        $this->actingAs($prototypeUser)->get('/products')->assertForbidden();
        foreach (['/inventory', '/logistics', '/customers', '/kol', '/marketing', '/accounting', '/settings', '/sync'] as $path) {
            $this->actingAs($prototypeUser)->get($path)->assertForbidden();
        }
    }

    public function test_every_formal_root_renders_real_content_instead_of_placeholder(): void
    {
        $permissionsByPath = [
            '/orders' => 'orders.view', '/logistics' => 'logistics.view', '/sync' => 'sync.view', '/customers' => 'customers.view',
            '/kol' => 'kol.view', '/marketing' => 'marketing.view', '/inventory' => 'inventory.view', '/purchase' => 'procurement.view',
            '/products' => 'products.view', '/guides' => 'guides.view', '/accounting' => 'accounting.view',
            '/settings' => 'system.permissions.manage',
        ];
        $user = $this->userWith(array_values($permissionsByPath), 'formal-operator');
        foreach ($permissionsByPath as $path => $permission) {
            $this->actingAs($user)->get($path)->assertInertia(fn (Assert $page) => $page
                ->component(match ($path) {
                    '/orders' => 'Orders/Index',
                    '/logistics' => 'Logistics/Index',
                    '/sync' => 'Sync/Index',
                    '/products' => 'Products/Dashboard',
                    '/inventory' => 'Inventory/Dashboard',
                    '/kol' => 'Kol/Overview',
                    '/marketing' => 'Marketing/Overview',
                    '/guides' => 'Guides/Index',
                    '/accounting' => 'Finance/ProfitDashboard',
                    '/settings' => 'Settings/Index',
                    '/customers' => 'Customers/Dashboard',
                    '/purchase' => 'Procurement/Orders/Index',
                    default => 'Shell/ModuleOverview',
                }));
        }
    }

    public function test_users_without_formal_or_prototype_permission_receive_forbidden_for_all_modules(): void
    {
        $user = $this->userWith([], 'restricted-operator');
        foreach (['/orders', '/logistics', '/sync', '/customers', '/kol', '/marketing', '/inventory', '/purchase', '/procurement', '/products', '/guides', '/accounting', '/settings'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_super_admin_still_respects_yby_four_module_navigation_profile(): void
    {

        $user = $this->userWith([], 'super_admin');
        $this->actingAs($user)->get('/settings')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 4)
            ->where('shellNavigation.0.key', 'dashboard')
            ->where('shellNavigation.1.key', 'customers')
            ->where('shellNavigation.2.key', 'products')
            ->where('shellNavigation.3.key', 'settings'));
    }

    public function test_yby_legacy_prototype_permission_does_not_grant_logistics_access(): void
    {

        $user = $this->userWith(['dashboard.view', 'prototype.view']);
        $this->actingAs($user)->get('/')->assertRedirectToRoute('dashboard');
        $this->actingAs($user)->get('/logistics/pending')->assertForbidden();
        $this->assertSame(
            ['dashboard'],
            array_column(app(ErpShellNavigation::class)->for($user), 'key')
        );
    }

    public function test_kol_orders_surface_requires_the_real_orders_permission_not_prototype(): void
    {
        $prototypeOnly = $this->userWith(['dashboard.view', 'prototype.view'], 'prototype-only');
        $this->actingAs($prototypeOnly)->get('/kol/orders')->assertForbidden();

        $withOrdersView = $this->userWith(['dashboard.view', 'orders.view'], 'orders-view');
        $this->actingAs($withOrdersView)->get('/kol/orders')->assertOk();
    }

    public function test_no_public_registration_route_is_introduced(): void
    {
        $this->assertFalse(app('router')->has('register'));
        $this->post('/register')->assertNotFound();
    }
}
