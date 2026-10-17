<?php

namespace Tests\Feature\Products;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CrossProjectProductRelationTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $role = Role::create(['key' => 'cross-project-product-viewer', 'label' => 'Cross Project Product Viewer']);
        $permission = Permission::firstOrCreate(['key' => 'products.view'], ['label' => 'products.view']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function projects(): array
    {
        return [
            'irrigation' => BusinessProject::create([
                'project_key' => 'yby-irrigation',
                'name' => 'YBY Irrigation',
                'status' => 'active',
            ]),
            'bottle' => BusinessProject::create([
                'project_key' => 'yby-bottle',
                'name' => 'YBY Bottle',
                'status' => 'active',
            ]),
        ];
    }

    public function test_null_sku_global_product_can_be_explicitly_activated_by_product_id_for_multiple_projects(): void
    {
        $projects = $this->projects();
        $product = Product::create([
            'sku' => null,
            'name' => 'Shared Global Product',
            'source_type' => 'wordpress_product_page',
            'product_type' => 'catalog',
        ]);

        $this->artisan('products:project:activate', [
            'project' => 'yby-irrigation',
            '--product-id' => [(string) $product->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->artisan('products:project:activate', [
            'project' => 'yby-bottle',
            '--product-id' => [(string) $product->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('business_project_products', 2);
        $this->assertDatabaseHas('business_project_products', [
            'business_project_id' => $projects['irrigation']->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('business_project_products', [
            'business_project_id' => $projects['bottle']->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_project_context_fields_are_isolated_while_global_product_truth_is_shared(): void
    {
        $projects = $this->projects();
        $product = Product::create([
            'sku' => 'GLOBAL-001',
            'name' => 'Shared Global Truth',
            'source_type' => 'manual',
            'weight_kg' => 1.25,
        ]);

        BusinessProjectProduct::create([
            'business_project_id' => $projects['irrigation']->id,
            'product_id' => $product->id,
            'project_product_name' => 'Irrigation Commercial Name',
            'category' => 'drip-irrigation-system',
            'sales_status' => 'active',
            'marketing_status' => 'active',
            'primary_site' => 'https://ybyirrigation.com/example.html',
        ]);

        BusinessProjectProduct::create([
            'business_project_id' => $projects['bottle']->id,
            'product_id' => $product->id,
            'project_product_name' => 'Bottle Commercial Name',
            'category' => 'bottle-accessories',
            'sales_status' => 'inactive',
            'marketing_status' => 'active',
            'primary_site' => 'https://ybybottle.com/example.html',
        ]);

        $irrigation = BusinessProjectProduct::query()
            ->where('business_project_id', $projects['irrigation']->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $bottle = BusinessProjectProduct::query()
            ->where('business_project_id', $projects['bottle']->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('Irrigation Commercial Name', $irrigation->project_product_name);
        $this->assertSame('Bottle Commercial Name', $bottle->project_product_name);
        $this->assertSame('active', $irrigation->sales_status);
        $this->assertSame('inactive', $bottle->sales_status);
        $this->assertSame($irrigation->product_id, $bottle->product_id);
        $this->assertSame(1.25, (float) $product->fresh()->weight_kg);
    }

    public function test_project_product_360_renders_each_projects_own_context_for_the_same_global_product(): void
    {
        $projects = $this->projects();
        $product = Product::create([
            'sku' => 'GLOBAL-360',
            'name' => 'Shared Global Product',
            'source_type' => 'manual',
        ]);

        BusinessProjectProduct::create([
            'business_project_id' => $projects['irrigation']->id,
            'product_id' => $product->id,
            'project_product_name' => 'Irrigation 360 Name',
            'category' => 'irrigation',
        ]);

        BusinessProjectProduct::create([
            'business_project_id' => $projects['bottle']->id,
            'product_id' => $product->id,
            'project_product_name' => 'Bottle 360 Name',
            'category' => 'bottle',
        ]);

        $viewer = $this->viewer();

        $this->actingAs($viewer)
            ->get("/projects/yby-irrigation/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Project/Show')
                ->where('project.key', 'yby-irrigation')
                ->where('relation.project_name', 'Irrigation 360 Name')
                ->where('relation.category', 'irrigation')
                ->where('product.name', 'Shared Global Product'));

        $this->actingAs($viewer)
            ->get("/projects/yby-bottle/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Project/Show')
                ->where('project.key', 'yby-bottle')
                ->where('relation.project_name', 'Bottle 360 Name')
                ->where('relation.category', 'bottle')
                ->where('product.name', 'Shared Global Product'));
    }
}
