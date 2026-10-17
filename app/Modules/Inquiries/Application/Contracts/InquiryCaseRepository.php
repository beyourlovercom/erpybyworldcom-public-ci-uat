<?php

namespace App\Modules\Inquiries\Application\Contracts;

use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Support\Collection;

interface InquiryCaseRepository
{
    /** @return Collection<int, object> */
    public function listForProject(int $projectId, array $filters = []): Collection;

    public function countsForProject(int $projectId, ?int $ownerId = null): array;

    public function findSourceRecord(int $projectId, string $sourceChannel, string $connectionKey, string $externalId): ?InquiryCase;

    public function create(array $attributes): InquiryCase;

    public function findForProject(int $projectId, int $caseId): InquiryCase;

    /** @return Collection<int, object> */
    public function listAssignableOwners(): Collection;

    /** @return Collection<int, InquiryCase> */
    public function listAttributionCandidatesForProject(int $projectId): Collection;

    public function findAttributionCandidate(int $caseId): ?InquiryCase;

    public function upsertAttributionResolution(int $caseId, array $resolution): object;

    public function updateAttribution(int $caseId, array $attribution): void;
}
