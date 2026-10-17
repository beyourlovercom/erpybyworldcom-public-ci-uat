<?php

namespace Tests\Feature\Products;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProjectProductWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $role = Role::create(['key' => 'product-viewer', 'label' => 'Product Viewer']);
        $permission = Permission::firstOrCreate(['key' => 'products.view'], ['label' => 'products.view']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_project_workspace_is_scoped_by_business_project(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $other = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);
        $product = Product::create(['sku' => 'YBY-001', 'name' => 'Global Product', 'source_type' => 'manual']);
        BusinessProjectProduct::create(['business_project_id' => $project->id, 'product_id' => $product->id, 'project_product_name' => 'Irrigation Product']);
        BusinessProjectProduct::create(['business_project_id' => $other->id, 'product_id' => $product->id, 'project_product_name' => 'Bottle Product']);

        $this->actingAs($this->viewer())->get('/projects/yby-irrigation/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Project/Index')
                ->where('project.key', 'yby-irrigation')
                ->has('products', 1)
                ->where('products.0.project_name', 'Irrigation Product'));
    }

    public function test_activation_is_dry_run_by_default_and_apply_is_idempotent(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $product = Product::create(['sku' => 'YBY-001', 'name' => 'Global Product', 'source_type' => 'manual']);

        $this->artisan('products:project:activate', ['project' => 'yby-irrigation', '--sku' => ['YBY-001']])->assertSuccessful();
        $this->assertDatabaseCount('business_project_products', 0);

        $this->artisan('products:project:activate', ['project' => 'yby-irrigation', '--sku' => ['YBY-001'], '--apply' => true])->assertSuccessful();
        $this->artisan('products:project:activate', ['project' => 'yby-irrigation', '--sku' => ['YBY-001'], '--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('business_project_products', 1);
        $this->assertDatabaseHas('business_project_products', ['business_project_id' => $project->id, 'product_id' => $product->id]);
    }

    public function test_project_product_360_returns_project_context_and_global_truth(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $product = Product::create(['sku' => null, 'name' => 'Global Drip Tape', 'source_type' => 'wordpress_product_page', 'product_type' => 'catalog']);
        BusinessProjectProduct::create([
            'business_project_id' => $project->id,
            'product_id' => $product->id,
            'project_product_name' => '16mm Drip Tape for Precision Irrigation',
            'category' => 'drip-irrigation-system',
            'primary_site' => 'https://ybyirrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html',
        ]);
        ProductExternalReference::create([
            'product_id' => $product->id,
            'provider' => 'wordpress',
            'connection_key' => 'ybyirrigation.com',
            'reference' => '762',
            'reference_type' => 'product',
        ]);

        $this->actingAs($this->viewer())->get("/projects/yby-irrigation/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Project/Show')
                ->where('project.key', 'yby-irrigation')
                ->where('relation.project_name', '16mm Drip Tape for Precision Irrigation')
                ->where('product.name', 'Global Drip Tape')
                ->where('product.sku', null)
                ->where('externalReferences.0.connection_key', 'ybyirrigation.com')
                ->where('externalReferences.0.reference', '762'));
    }

    public function test_project_product_360_does_not_leak_unrelated_project_product(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $bottle = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);
        $product = Product::create(['sku' => 'BOTTLE-1', 'name' => 'Bottle Product', 'source_type' => 'manual']);
        BusinessProjectProduct::create(['business_project_id' => $bottle->id, 'product_id' => $product->id, 'project_product_name' => 'Bottle Product']);

        $this->actingAs($this->viewer())
            ->get("/projects/yby-irrigation/products/{$product->id}")
            ->assertNotFound();
    }
}
