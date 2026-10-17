<?php

namespace App\Modules\Inquiries\Infrastructure\Repositories;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use App\Modules\Inquiries\Infrastructure\Models\CaseAttribution;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Support\Collection;

final class EloquentInquiryCaseRepository implements InquiryCaseRepository
{
    public function listForProject(int $projectId, array $filters = []): Collection
    {
        return InquiryCase::query()
            ->with(['company:id,name', 'owner:id,name,email'])
            ->where('business_project_id', $projectId)
            ->when(isset($filters['owner_id']), fn ($q) => $q->where('owner_id', (int) $filters['owner_id']))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('case_status', $filters['status']))
            ->when(($filters['stage'] ?? '') !== '', fn ($q) => $q->where('case_stage', $filters['stage']))
            ->when(($filters['source'] ?? '') !== '', fn ($q) => $q->where('source_channel', $filters['source']))
            ->orderByDesc('last_inbound_at')
            ->limit(200)
            ->get();
    }

    public function countsForProject(int $projectId, ?int $ownerId = null): array
    {
        $base = InquiryCase::query()->where('business_project_id', $projectId)
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId));
        return [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->where('case_status', 'open')->count(),
            'waiting' => (clone $base)->where('handling_state', 'waiting')->count(),
            'unresolved_identity' => (clone $base)->where('identity_resolution_state', 'unresolved')->count(),
        ];
    }

    public function findSourceRecord(int $projectId, string $sourceChannel, string $connectionKey, string $externalId): ?InquiryCase
    {
        return InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->where('source_channel', $sourceChannel)
            ->where('source_connection_key', $connectionKey)
            ->where('source_external_id', $externalId)
            ->first();
    }

    public function create(array $attributes): InquiryCase
    {
        return InquiryCase::create($attributes);
    }

    public function findForProject(int $projectId, int $caseId): InquiryCase
    {
        return InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->findOrFail($caseId);
    }

    public function listAssignableOwners(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function listAttributionCandidatesForProject(int $projectId): Collection
    {
        return InquiryCase::query()
            ->where('business_project_id', $projectId)
            ->orderBy('id')
            ->get(['id', 'case_number', 'business_project_id', 'source_channel', 'attribution']);
    }

    public function findAttributionCandidate(int $caseId): ?InquiryCase
    {
        return InquiryCase::query()->find($caseId);
    }

    public function updateAttribution(int $caseId, array $attribution): void
    {
        $case = InquiryCase::query()->findOrFail($caseId);
        $case->attribution = $attribution;
        $case->save();
    }

    public function upsertAttributionResolution(int $caseId, array $resolution): object
    {
        $case = InquiryCase::query()->findOrFail($caseId);

        return CaseAttribution::updateOrCreate(
            ['case_id' => $case->id],
            array_merge($resolution, ['business_project_id' => $case->business_project_id]),
        );
    }
}