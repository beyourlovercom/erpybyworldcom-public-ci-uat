<?php

namespace App\Modules\BusinessProjects\Application\Contracts;

use Illuminate\Support\Collection;

interface BusinessProjectRepository
{
    public function findByKey(string $projectKey): ?object;

    public function findSiteByReference(string $reference): ?object;

    /** @return Collection<int, object> */
    public function listActive(): Collection;

    /** @return Collection<int, object> */
    public function listSites(int $projectId): Collection;

    public function normalizeDomain(string $url): string;
}