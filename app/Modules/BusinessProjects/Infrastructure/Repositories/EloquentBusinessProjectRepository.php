<?php

namespace App\Modules\BusinessProjects\Infrastructure\Repositories;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use Illuminate\Support\Collection;

final class EloquentBusinessProjectRepository implements BusinessProjectRepository
{
    public function findByKey(string $projectKey): ?object
    {
        return BusinessProject::where('project_key', $projectKey)->first();
    }

    public function findSiteByReference(string $reference): ?object
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $domain = $this->normalizeDomain($reference);

        return BusinessProjectSite::query()
            ->with('project')
            ->where('status', 'active')
            ->where(function ($query) use ($reference, $domain): void {
                $query->where('site_key', $reference)->orWhere('domain_normalized', $domain);
            })
            ->first();
    }

    public function listActive(): Collection
    {
        return BusinessProject::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();
    }

    public function listSites(int $projectId): Collection
    {
        return BusinessProjectSite::where('business_project_id', $projectId)
            ->where('status', 'active')
            ->orderBy('is_primary', 'desc')
            ->orderBy('id')
            ->get();
    }

    public function normalizeDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        return strtolower(rtrim((string) $host, '.'));
    }
}