<?php

namespace App\Modules\Inquiries\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use App\Modules\Traffic\Application\TrafficService;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class InquiryAttributionService
{
    public function __construct(
        private readonly InquiryCaseRepository $cases,
        private readonly BusinessProjectService $projects,
        private readonly TrafficService $traffic,
    ) {}

    public function resolveProject(string $projectKey, bool $apply = false): array
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        $summary = ['mode' => $apply ? 'apply' : 'dry_run', 'project_key' => $projectKey, 'seen' => 0, 'resolved' => 0, 'partial' => 0, 'unresolved' => 0, 'rows_applied' => 0, 'rows' => []];
        foreach ($this->cases->listAttributionCandidatesForProject((int) $project->id) as $case) {
            $summary['seen']++;
            $resolution = $this->buildResolution($case);
            $summary[$resolution['attribution_state']]++;
            $summary['rows'][] = ['case_number' => $case->case_number, 'source_kind' => $resolution['source_kind'], 'state' => $resolution['attribution_state'], 'campaign_match' => $resolution['campaign_match_state'], 'landing_match' => $resolution['landing_page_match_state']];
            if ($apply) {
                $this->persist((int) $case->id, $resolution);
                $summary['rows_applied']++;
            }
        }

        return $summary;
    }

    public function resolveCase(int $caseId, bool $apply = true): array
    {
        $case = $this->cases->findAttributionCandidate($caseId);
        if (! $case) {
            throw new InvalidArgumentException('Inquiry Case not found.');
        }
        $resolution = $this->buildResolution($case);
        if ($apply) {
            $this->persist($caseId, $resolution);
        }
        return $resolution;
    }

    private function buildResolution(object $case): array
    {
        $raw = is_array($case->attribution ?? null) ? $case->attribution : [];
        $evidence = array_filter([
            'utm_source' => $this->text(Arr::get($raw, 'utm_source')),
            'utm_medium' => $this->text(Arr::get($raw, 'utm_medium')),
            'utm_campaign' => $this->text(Arr::get($raw, 'utm_campaign')),
            'utm_term' => $this->text(Arr::get($raw, 'utm_term')),
            'gclid' => $this->text(Arr::get($raw, 'gclid')),
            'fbclid' => $this->text(Arr::get($raw, 'fbclid')),
            'source_url' => $this->text(Arr::get($raw, 'source_url')),
        ], fn ($value) => $value !== null);

        $traffic = $this->traffic->resolveAttributionEvidence((int) $case->business_project_id, $evidence);
        $sourceKind = $this->sourceKind($evidence, (string) ($case->source_channel ?? ''));
        $paidSignal = $this->isPaidSignal($evidence);
        $campaignState = $traffic['campaign_match_state'];
        $landingState = $traffic['landing_page_match_state'];
        $hasSourceSignal = ($evidence['utm_source'] ?? null) !== null || ($evidence['utm_medium'] ?? null) !== null;

        if ($paidSignal) {
            $state = $campaignState === 'matched' && in_array($landingState, ['matched', 'not_applicable'], true)
                ? 'resolved'
                : (($campaignState === 'matched' || $landingState === 'matched' || $hasSourceSignal) ? 'partial' : 'unresolved');
        } elseif ($hasSourceSignal) {
            $state = $landingState === 'unresolved' ? 'partial' : 'resolved';
        } elseif (($evidence['source_url'] ?? null) !== null) {
            $state = $landingState === 'matched' ? 'resolved' : 'partial';
        } else {
            $state = 'unresolved';
        }

        $firstTouch = $evidence;
        $lastTouch = array_filter([
            'source_channel' => $this->text($case->source_channel ?? null),
            'source_page' => $this->text(Arr::get(is_array($case->source_payload ?? null) ? $case->source_payload : [], 'source_metadata.source_page')),
            'submitted_at' => isset($case->first_inbound_at) ? (string) $case->first_inbound_at : null,
        ], fn ($value) => $value !== null);
        $hashEvidence = ['first_touch' => $firstTouch, 'last_touch' => $lastTouch];
        ksort($hashEvidence['first_touch']);
        ksort($hashEvidence['last_touch']);

        return array_merge($evidence, $traffic, [
            'source_kind' => $sourceKind,
            'attribution_state' => $state,
            'evidence_hash' => hash('sha256', json_encode($hashEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'evidence' => ['first_touch' => $firstTouch, 'last_touch' => $lastTouch],
            'resolved_at' => now(),
        ]);
    }

    private function persist(int $caseId, array $resolution): void
    {
        $this->cases->upsertAttributionResolution($caseId, $resolution);
        $case = $this->cases->findAttributionCandidate($caseId);
        $raw = is_array($case?->attribution ?? null) ? $case->attribution : [];
        $raw['resolution'] = [
            'source_kind' => $resolution['source_kind'],
            'attribution_state' => $resolution['attribution_state'],
            'traffic_source_account_id' => $resolution['traffic_source_account_id'],
            'traffic_campaign_id' => $resolution['traffic_campaign_id'],
            'landing_page_id' => $resolution['landing_page_id'],
            'campaign_match_state' => $resolution['campaign_match_state'],
            'landing_page_match_state' => $resolution['landing_page_match_state'],
            'match_method' => $resolution['match_method'],
            'evidence_hash' => $resolution['evidence_hash'],
        ];
        $this->cases->updateAttribution($caseId, $raw);
    }

    private function sourceKind(array $evidence, string $fallback): string
    {
        $source = strtolower((string) ($evidence['utm_source'] ?? ''));
        $medium = strtolower((string) ($evidence['utm_medium'] ?? ''));
        if (($evidence['gclid'] ?? null) !== null || ($source === 'google' && in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search'], true))) return 'google_ads';
        if (($evidence['fbclid'] ?? null) !== null) return 'meta';
        if ($medium === 'organic') return 'organic_search';
        if ($source !== '') return 'utm';
        if (($evidence['source_url'] ?? null) !== null) return 'direct_or_unknown';
        return $fallback !== '' ? $fallback : 'unknown';
    }

    private function isPaidSignal(array $evidence): bool
    {
        $source = strtolower((string) ($evidence['utm_source'] ?? ''));
        $medium = strtolower((string) ($evidence['utm_medium'] ?? ''));
        return ($evidence['gclid'] ?? null) !== null
            || ($evidence['fbclid'] ?? null) !== null
            || in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid_social', 'paidsocial'], true);
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
