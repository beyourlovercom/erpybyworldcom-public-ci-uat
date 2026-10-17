<?php

namespace App\Modules\Inquiries\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use App\Modules\Inquiries\Application\Exceptions\InquiryIntakeConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InquiryIntakeService
{
    private const SOURCES = ['website', 'whatsapp', 'xiaoman', 'manual'];

    public function __construct(
        private readonly InquiryCaseRepository $cases,
        private readonly BusinessProjectService $projects,
    ) {}

    public function ingest(array $input): array
    {
        $source = strtolower(trim((string) ($input['source_channel'] ?? '')));
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported inquiry source.');
        }

        [$project, $site, $connectionKey] = $this->resolveProject($input, $source);
        $externalId = trim((string) ($input['source_external_id'] ?? ''));
        if ($externalId === '') {
            throw new InvalidArgumentException('source_external_id is required.');
        }

        $payload = $this->canonicalize($input);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $payloadJson);
        $existing = $this->cases->findSourceRecord((int) $project->id, $source, $connectionKey, $externalId);
        if ($existing) {
            if (! hash_equals((string) $existing->source_payload_hash, $hash)) {
                throw new InquiryIntakeConflict('The external inquiry record already exists with different content.');
            }

            return ['created' => false, 'case' => $existing, 'project' => $project];
        }

        $firstInbound = CarbonImmutable::parse((string) ($input['first_inbound_at'] ?? now()->toIso8601String()));
        $lastInbound = CarbonImmutable::parse((string) ($input['last_inbound_at'] ?? $firstInbound->toIso8601String()));
        if ($lastInbound->lt($firstInbound)) {
            throw new InvalidArgumentException('last_inbound_at cannot be earlier than first_inbound_at.');
        }

        $case = DB::transaction(fn () => $this->cases->create([
            'case_number' => $this->caseNumber((string) $project->project_key, $source, $connectionKey, $externalId, $firstInbound),
            'business_project_id' => (int) $project->id,
            'title' => $this->title($input, $source),
            'contact_name' => $this->nullable($input['contact_name'] ?? null),
            'contact_email' => $this->normalizedEmail($input['contact_email'] ?? null),
            'contact_phone' => $this->nullable($input['contact_phone'] ?? null),
            'company_name_snapshot' => $this->nullable($input['company_name'] ?? null),
            'country_snapshot' => $this->nullable($input['country'] ?? null),
            'buyer_type' => $this->nullable($input['buyer_type'] ?? null),
            'product_interest' => $this->nullable($input['product_interest'] ?? null),
            'quantity' => $this->nullable($input['quantity'] ?? null),
            'source_channel' => $source,
            'source_connection_key' => $connectionKey,
            'source_external_id' => $externalId,
            'source_payload_hash' => $hash,
            'brand_key' => (string) $project->project_key,
            'source_site_key' => $site?->site_key,
            'case_status' => 'open',
            'case_stage' => 'new',
            'handling_state' => 'waiting',
            'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => $firstInbound,
            'last_inbound_at' => $lastInbound,
            'attention_since_at' => $firstInbound,
            'attribution' => is_array($input['attribution'] ?? null) ? $input['attribution'] : null,
            'source_payload' => $payload,
        ]));

        return ['created' => true, 'case' => $case, 'project' => $project];
    }

    private function resolveProject(array $input, string $source): array
    {
        $project = null;
        $site = null;
        $projectKey = trim((string) ($input['project_key'] ?? ''));
        $siteReference = trim((string) ($input['site'] ?? ''));

        if ($projectKey !== '') {
            $project = $this->projects->findByKey($projectKey);
            if (! $project || $project->status !== 'active') {
                throw new InvalidArgumentException('Business project not found.');
            }
        }

        if ($siteReference !== '') {
            $site = $this->projects->findSiteByReference($siteReference);
            if (! $site || ! $site->project || $site->project->status !== 'active') {
                throw new InvalidArgumentException('Active project site not found.');
            }
            if ($project && (int) $project->id !== (int) $site->business_project_id) {
                throw new InvalidArgumentException('project_key conflicts with the resolved site project.');
            }
            $project = $site->project;
        }

        if (! $project) {
            throw new InvalidArgumentException('project_key or site is required.');
        }
        if ($source === 'website' && ! $site) {
            throw new InvalidArgumentException('Website inquiry intake requires a registered site reference.');
        }

        $connectionKey = $site?->site_key ?: trim((string) ($input['source_connection_key'] ?? ''));
        if ($connectionKey === '') {
            throw new InvalidArgumentException('source_connection_key is required for non-website sources.');
        }

        return [$project, $site, $connectionKey];
    }

    private function caseNumber(string $projectKey, string $source, string $connectionKey, string $externalId, CarbonImmutable $time): string
    {
        $token = match ($projectKey) {
            'yby-irrigation' => 'IRR',
            'yby-bottle' => 'BOT',
            'yby-world' => 'WRL',
            default => strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $projectKey), 0, 3)),
        };
        $suffix = strtoupper(substr(hash('sha256', implode('|', [$projectKey, $source, $connectionKey, $externalId])), 0, 6));

        return 'YBY-'.$token.'-'.$time->format('ymd').'-'.$suffix;
    }

    private function title(array $input, string $source): string
    {
        $title = $this->nullable($input['title'] ?? null);
        if ($title) {
            return $title;
        }
        $who = $this->nullable($input['company_name'] ?? null) ?: $this->nullable($input['contact_name'] ?? null) ?: 'New lead';
        return ucfirst($source).' inquiry · '.$who;
    }

    private function normalizedEmail(mixed $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : strtolower($value);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        return $value;
    }
}