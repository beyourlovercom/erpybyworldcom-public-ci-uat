<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectProductShowController
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    public function __invoke(Request $request, string $projectKey, int $product): Response
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new NotFoundHttpException('Business project not found.');
        }

        $relation = BusinessProjectProduct::query()
            ->with('product')
            ->where('business_project_id', $project->id)
            ->where('product_id', $product)
            ->first();

        if (! $relation || ! $relation->product) {
            throw new NotFoundHttpException('Project product not found.');
        }

        $master = $relation->product;
        $externalReferences = ProductExternalReference::query()
            ->where('product_id', $master->id)
            ->orderBy('provider')
            ->orderBy('connection_key')
            ->get()
            ->map(fn (ProductExternalReference $ref): array => [
                'provider' => $ref->provider,
                'connection_key' => $ref->connection_key,
                'reference' => $ref->reference,
                'reference_type' => $ref->reference_type,
            ])->values()->all();

        return Inertia::render('Products/Project/Show', [
            'project' => [
                'key' => $project->project_key,
                'name' => $project->name,
                'icon_url' => config("yby_erp.project_branding.{$project->project_key}.icon_url"),
                'emoji' => (string) config("yby_erp.project_branding.{$project->project_key}.fallback_emoji", '📦'),
            ],
            'relation' => [
                'id' => $relation->id,
                'project_name' => $relation->project_product_name,
                'category' => $relation->category,
                'status' => $relation->status,
                'sales_status' => $relation->sales_status,
                'marketing_status' => $relation->marketing_status,
                'primary_site' => $relation->primary_site,
                'created_at' => $relation->created_at?->toIso8601String(),
                'updated_at' => $relation->updated_at?->toIso8601String(),
            ],
            'product' => [
                'id' => $master->id,
                'name' => $master->name,
                'sku' => $master->sku,
                'gtin' => $master->gtin,
                'source_type' => $master->source_type,
                'product_type' => $master->product_type,
                'is_active' => (bool) $master->is_active,
                'unit' => $master->unit,
                'weight_kg' => $master->weight_kg,
                'shipping_weight_kg' => $master->shipping_weight_kg,
                'length_cm' => $master->length_cm,
                'width_cm' => $master->width_cm,
                'height_cm' => $master->height_cm,
                'country_of_origin' => $master->country_of_origin,
                'hs_code' => $master->hs_code,
                'sale_price' => $master->sale_price,
                'regular_price' => $master->regular_price,
                'standard_production_cost' => $master->standard_production_cost,
                'standard_packaging_cost' => $master->standard_packaging_cost,
                'standard_purchase_cost' => $master->standard_purchase_cost,
                'cost_currency' => $master->cost_currency,
            ],
            'externalReferences' => $externalReferences,
            'links' => [
                'project_products' => route('products.project', ['projectKey' => $project->project_key]),
                'global_product' => route('products.show', ['id' => $master->id]),
                'sales' => "/projects/{$project->project_key}/sales",
                'traffic' => "/projects/{$project->project_key}/traffic",
            ],
            'canOperate' => (bool) $request->user()?->hasPermission('products.operate'),
        ]);
    }
}
