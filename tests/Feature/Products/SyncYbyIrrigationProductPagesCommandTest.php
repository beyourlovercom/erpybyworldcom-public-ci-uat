<?php

namespace Tests\Feature\Products;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SyncYbyIrrigationProductPagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessProject::query()->create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);

        Http::fake([
            '*' => Http::response([
                [
                    'id' => 762,
                    'slug' => 'precision-irrigation-drip-tape',
                    'title' => ['rendered' => '16mm Drip Tape for Precision Irrigation'],
                    'link' => 'https://ybyirrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html',
                    'modified' => '2026-09-18T02:22:21',
                    'status' => 'publish',
                ],
                [
                    'id' => 765,
                    'slug' => 'drip-irrigation-accessories',
                    'title' => ['rendered' => 'Drip Irrigation Accessories and Parts'],
                    'link' => 'https://ybyirrigation.com/pipes-pipe-fittings/drip-irrigation-accessories.html',
                    'modified' => '2026-09-18T02:24:36',
                    'status' => 'publish',
                ],
            ], 200),
        ]);
    }

    public function test_default_mode_is_dry_run_and_writes_nothing(): void
    {
        $this->artisan('products:yby-irrigation:sync-product-pages')->assertSuccessful();

        Http::assertSent(fn (Request $request) =>
            str_starts_with($request->url(), 'https://ybyirrigation.com/wp-json/wp/v2/product_page')
        );

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_external_references', 0);
        $this->assertDatabaseCount('business_project_products', 0);
    }

    public function test_apply_creates_null_sku_products_and_is_idempotent(): void
    {
        $this->artisan('products:yby-irrigation:sync-product-pages', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_external_references', 2);
        $this->assertDatabaseCount('business_project_products', 2);

        $this->assertDatabaseHas('products', [
            'sku' => null,
            'source_type' => 'wordpress_product_page',
            'product_type' => 'catalog',
            'name' => '16mm Drip Tape for Precision Irrigation',
        ]);

        $this->assertDatabaseHas('product_external_references', [
            'provider' => 'wordpress',
            'connection_key' => 'ybyirrigation.com',
            'reference' => '762',
            'reference_type' => 'product',
        ]);

        $this->assertDatabaseHas('business_project_products', [
            'project_product_name' => '16mm Drip Tape for Precision Irrigation',
            'category' => 'drip-irrigation-system',
            'primary_site' => 'https://ybyirrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html',
        ]);

        $this->artisan('products:yby-irrigation:sync-product-pages', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_external_references', 2);
        $this->assertDatabaseCount('business_project_products', 2);
    }
}
