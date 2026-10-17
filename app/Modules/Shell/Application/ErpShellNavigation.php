<?php

namespace App\Modules\Shell\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class ErpShellNavigation
{
    public function __construct(private readonly BusinessProjectService $projects) {}

    public function for(Authenticatable $user): array
    {
        $profile = config('yby_erp.navigation_profile', []);
        $base = collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => array_key_exists($item['key'], $profile) && $this->allowed($user, $item))
            ->map(function (array $item) use ($user, $profile): array {
                $serialized = $this->serialize($user, $item);
                $allowedChildren = $profile[$item['key']] ?? null;
                if (is_array($allowedChildren)) {
                    $serialized['children'] = collect($serialized['children'])
                        ->filter(fn (array $child) => in_array($child['key'], $allowedChildren, true))
                        ->values()->all();
                }

                return $serialized;
            })->values()->all();

        $projects = $this->projectItems($user);
        $result = [];
        $projectsInserted = false;
        foreach ($base as $item) {
            $result[] = $item;
            if ($item['key'] === 'dashboard') {
                $result = [...$result, ...$projects];
                $projectsInserted = true;
            }
        }

        return $projectsInserted ? $result : [...$projects, ...$result];
    }

    private function projectItems(Authenticatable $user): array
    {
        return $this->projects->listActive()->map(function ($project) use ($user): array {
            $key = (string) $project->project_key;
            $children = [];
            if (Gate::forUser($user)->allows('dashboard.view')) {
                $label = Gate::forUser($user)->allows('marketing.view') ? '老板看板' : '项目总览';
                $children[] = $this->leaf("project-{$key}-overview", $label, "/projects/{$key}", 'projects.show');
            }
            if (Gate::forUser($user)->allows('customers.view')) {
                $children[] = $this->leaf("project-{$key}-inquiries", '询盘', "/projects/{$key}/inquiries", 'inquiries.index');
                $children[] = $this->leaf("project-{$key}-customers", '客户', "/projects/{$key}/customers", 'customers.project');
            }
            if (Gate::forUser($user)->allows('products.view')) {
                $children[] = $this->leaf("project-{$key}-products", '产品', "/projects/{$key}/products", 'products.project');
            }
            if (Gate::forUser($user)->allows('sales.view')) {
                $children[] = $this->leaf("project-{$key}-sales", '销售', "/projects/{$key}/sales", 'sales.index');
            }
            if (Gate::forUser($user)->allows('marketing.view')) {
                $traffic = $this->leaf("project-{$key}-traffic", '流量', "/projects/{$key}/traffic", 'traffic.project-dashboard');
                $traffic['children'] = [
                    $this->leaf("project-{$key}-traffic-ga4", 'GA4', "/projects/{$key}/traffic/ga4", 'traffic.project-ga4'),
                    $this->leaf("project-{$key}-traffic-landing", 'Landing Pages', "/projects/{$key}/traffic/landing-pages", 'traffic.project-landing-pages'),
                    $this->leaf("project-{$key}-traffic-seo", 'SEO Growth', "/projects/{$key}/traffic/seo", 'traffic.project-seo'),
                ];
                $children[] = $traffic;
            }

            return [
                'key' => 'project-'.$key,
                'label' => (string) $project->name,
                'emoji' => $this->projectEmoji($key),
                'icon_url' => $this->projectIconUrl($key),
                'path' => "/projects/{$key}",
                'route' => 'projects.show',
                'placeholder' => false,
                'group' => false,
                'children' => $children,
            ];
        })->filter(fn (array $item) => $item['children'] !== [])->values()->all();
    }

    private function leaf(string $key, string $label, string $path, string $route): array
    {
        return compact('key', 'label', 'path', 'route') + [
            'emoji' => null, 'icon_url' => null, 'placeholder' => false, 'group' => false, 'children' => [],
        ];
    }

    private function projectEmoji(string $key): string
    {
        return (string) config("yby_erp.project_branding.{$key}.fallback_emoji", '📁');
    }

    private function projectIconUrl(string $key): ?string
    {
        $value = config("yby_erp.project_branding.{$key}.icon_url");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function allowed(Authenticatable $user, array $item): bool
    {
        $permissionsAny = array_values(array_filter([$item['permission'] ?? null, ...($item['permissions_any'] ?? [])]));
        if ($permissionsAny !== [] && ! collect($permissionsAny)->contains(fn (string $permission) => Gate::forUser($user)->allows($permission))) {
            if (! ($item['allow_child_entry'] ?? false) || empty($item['children'])
                || ! collect($item['children'])->contains(fn (array $child) => $this->allowed($user, $child))) {
                return false;
            }
        }
        foreach ($item['permissions_all'] ?? [] as $permission) {
            if (! Gate::forUser($user)->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    private function serialize(Authenticatable $user, array $item): array
    {
        $children = collect($item['children'] ?? [])
            ->filter(fn (array $child) => empty($child['permission']) || $this->allowed($user, $child))
            ->map(fn (array $child) => $this->serialize($user, $child))->values()->all();

        return [
            'key' => $item['key'], 'label' => $item['label'], 'emoji' => $item['emoji'] ?? null,
            'icon_url' => $item['icon_url'] ?? null, 'path' => $item['path'], 'route' => $item['route'], 'placeholder' => $item['placeholder'],
            'group' => $item['group'] ?? false, 'children' => $children,
        ];
    }
}
