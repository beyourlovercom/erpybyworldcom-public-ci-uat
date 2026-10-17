<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Console\Command;

final class ActivateProjectProductsCommand extends Command
{
    protected $signature = 'products:project:activate
        {project}
        {--sku=* : Explicit global SKU(s)}
        {--product-id=* : Explicit global Product ID(s), for products without SKU}
        {--apply : Persist relations}';

    protected $description = 'Dry-run or activate explicit global products for one YBY business project.';

    public function handle(): int
    {
        $project = BusinessProject::query()
            ->where('project_key', (string) $this->argument('project'))
            ->where('status', 'active')
            ->first();

        if (! $project) {
            $this->error('Active business project not found.');
            return self::FAILURE;
        }

        $skus = collect($this->option('sku'))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $productIds = collect($this->option('product-id'))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($skus->isEmpty() && $productIds->isEmpty()) {
            $this->error('At least one explicit --sku or --product-id is required.');
            return self::FAILURE;
        }

        $invalidIds = $productIds->reject(fn ($id) => ctype_digit($id) && (int) $id > 0)->values();
        if ($invalidIds->isNotEmpty()) {
            $this->error('Invalid Product ID(s): '.$invalidIds->implode(', '));
            return self::FAILURE;
        }

        $productsBySku = $skus->isEmpty()
            ? collect()
            : Product::query()->whereIn('sku', $skus)->get()->keyBy('sku');

        $missingSkus = $skus->reject(fn ($sku) => $productsBySku->has($sku))->values();
        if ($missingSkus->isNotEmpty()) {
            $this->error('Unknown SKU(s): '.$missingSkus->implode(', '));
            return self::FAILURE;
        }

        $numericIds = $productIds->map(fn ($id) => (int) $id);
        $productsById = $numericIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $numericIds)->get()->keyBy('id');

        $missingIds = $numericIds->reject(fn ($id) => $productsById->has($id))->values();
        if ($missingIds->isNotEmpty()) {
            $this->error('Unknown Product ID(s): '.$missingIds->implode(', '));
            return self::FAILURE;
        }

        $products = $productsBySku
            ->values()
            ->concat($productsById->values())
            ->unique(fn (Product $product) => $product->id)
            ->values();

        $apply = (bool) $this->option('apply');
        $created = 0;
        $existing = 0;

        foreach ($products as $product) {
            $relation = BusinessProjectProduct::query()
                ->where('business_project_id', $project->id)
                ->where('product_id', $product->id)
                ->first();

            if ($relation) {
                $existing++;
                continue;
            }

            if ($apply) {
                BusinessProjectProduct::query()->create([
                    'business_project_id' => $project->id,
                    'product_id' => $product->id,
                ]);
            }

            $created++;
        }

        $this->line(json_encode([
            'mode' => $apply ? 'apply' : 'dry_run',
            'project' => $project->project_key,
            'seen' => $products->count(),
            'create' => $created,
            'existing' => $existing,
            'selection' => [
                'sku_count' => $skus->count(),
                'product_id_count' => $numericIds->count(),
            ],
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
