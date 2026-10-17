<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectProductController
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    public function __invoke(Request $request, string $projectKey): Response
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new NotFoundHttpException('Business project not found.');
        }

        $relations = BusinessProjectProduct::query()
            ->with('product')
            ->where('business_project_id', $project->id)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Products/Project/Index', [
            'project' => [
                'key' => $project->project_key,
                'name' => $project->name,
                'icon_url' => config("yby_erp.project_branding.{$project->project_key}.icon_url"),
                'emoji' => (string) config("yby_erp.project_branding.{$project->project_key}.fallback_emoji", '📦'),
            ],
            'metrics' => [
                'total' => $relations->count(),
                'active' => $relations->where('status', 'active')->count(),
                'sales_active' => $relations->where('sales_status', 'active')->count(),
                'marketing_active' => $relations->where('marketing_status', 'active')->count(),
            ],
            'products' => $relations->map(fn (BusinessProjectProduct $row): array => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->product?->sku,
                'global_name' => $row->product?->name,
                'project_name' => $row->project_product_name,
                'category' => $row->category,
                'status' => $row->status,
                'sales_status' => $row->sales_status,
                'marketing_status' => $row->marketing_status,
                'primary_site' => $row->primary_site,
            ])->values()->all(),
            'canOperate' => (bool) $request->user()?->hasPermission('products.operate'),
        ]);
    }
}
