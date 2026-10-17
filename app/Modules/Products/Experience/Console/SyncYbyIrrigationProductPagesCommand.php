<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Products\Infrastructure\Models\BusinessProjectProduct;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SyncYbyIrrigationProductPagesCommand extends Command
{
    private const SOURCE_BASE = 'https://ybyirrigation.com';
    private const CONNECTION_KEY = 'ybyirrigation.com';
    private const PROJECT_KEY = 'yby-irrigation';

    protected $signature = 'products:yby-irrigation:sync-product-pages
        {--limit=100 : Maximum published product pages to read}
        {--apply : Persist Global Product + external reference + project relation}';

    protected $description = 'Dry-run or activate ybyirrigation.com product_page records into the local YBY ERP Product OS.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This adapter is restricted to local/testing environments.');
            return self::FAILURE;
        }

        $project = BusinessProject::query()
            ->where('project_key', self::PROJECT_KEY)
            ->where('status', 'active')
            ->first();

        if (! $project) {
            $this->error('Active yby-irrigation business project not found.');
            return self::FAILURE;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $apply = (bool) $this->option('apply');

        try {
            $response = Http::acceptJson()
                ->withOptions(['verify' => false]) // local/testing-only fixed-source adapter; Windows PHP CA bundle is incomplete
                ->timeout(20)
                ->get(self::SOURCE_BASE.'/wp-json/wp/v2/product_page', [
                    'per_page' => $limit,
                    'status' => 'publish',
                    '_fields' => 'id,slug,title,link,modified,status',
                ])
                ->throw();

            $rows = $response->json();
            if (! is_array($rows)) {
                throw new RuntimeException('Product page source returned an invalid payload.');
            }
        } catch (\Throwable $e) {
            $this->error('YBY product_page source read failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'source' => self::CONNECTION_KEY,
            'project' => self::PROJECT_KEY,
            'seen' => 0,
            'create' => 0,
            'update' => 0,
            'unchanged' => 0,
            'relations' => 0,
            'blocked' => [],
            'manifest' => [],
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $title = $this->cleanTitle((string) data_get($row, 'title.rendered', ''));
            $link = trim((string) ($row['link'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            $modified = trim((string) ($row['modified'] ?? ''));

            if ($id <= 0 || $title === '' || ! $this->isAllowedSourceUrl($link)) {
                $summary['blocked'][] = ['id' => $id, 'reason' => 'invalid source identity/title/link'];
                continue;
            }

            $summary['seen']++;
            $summary['manifest'][] = [
                'id' => $id,
                'slug' => $slug,
                'name' => $title,
                'category' => $this->categoryFromLink($link),
                'link' => $link,
                'modified' => $modified,
            ];

            $existingRef = ProductExternalReference::query()
                ->where('provider', 'wordpress')
                ->where('connection_key', self::CONNECTION_KEY)
                ->where('reference', (string) $id)
                ->first();

            if (! $apply) {
                $existingRef ? $summary['unchanged']++ : $summary['create']++;
                continue;
            }

            DB::transaction(function () use ($project, $id, $title, $link, &$summary): void {
                $externalRef = ProductExternalReference::query()
                    ->where('provider', 'wordpress')
                    ->where('connection_key', self::CONNECTION_KEY)
                    ->where('reference', (string) $id)
                    ->lockForUpdate()
                    ->first();

                if ($externalRef) {
                    $product = Product::query()->lockForUpdate()->findOrFail($externalRef->product_id);
                    $product->fill([
                        'name' => $title,
                        'source_type' => 'wordpress_product_page',
                        'product_type' => 'catalog',
                        'is_active' => true,
                    ]);
                    if ($product->isDirty()) {
                        $product->save();
                        $summary['update']++;
                    } else {
                        $summary['unchanged']++;
                    }
                } else {
                    $product = Product::query()->create([
                        'woocommerce_product_id' => null,
                        'source_type' => 'wordpress_product_page',
                        'product_type' => 'catalog',
                        'sku' => null,
                        'name' => $title,
                        'is_active' => true,
                    ]);
                    ProductExternalReference::query()->create([
                        'product_id' => $product->id,
                        'provider' => 'wordpress',
                        'connection_key' => self::CONNECTION_KEY,
                        'reference' => (string) $id,
                        'reference_type' => 'product',
                    ]);
                    $summary['create']++;
                }

                BusinessProjectProduct::query()->updateOrCreate(
                    ['business_project_id' => $project->id, 'product_id' => $product->id],
                    [
                        'project_product_name' => $title,
                        'category' => $this->categoryFromLink($link),
                        'status' => 'active',
                        'sales_status' => 'active',
                        'marketing_status' => 'active',
                        'primary_site' => $link,
                    ],
                );
                $summary['relations']++;
            });
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $summary['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function cleanTitle(string $title): string
    {
        return trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function isAllowedSourceUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === self::CONNECTION_KEY;
    }

    private function categoryFromLink(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        return count($segments) > 1 ? $segments[0] : null;
    }
}
