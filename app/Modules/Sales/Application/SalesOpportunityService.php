<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SalesOpportunityService
{
    private const STAGES = ['discovery', 'qualified', 'solution', 'quoted', 'negotiating'];

    private const ROADMAP_STAGES = ['discovery', 'qualified', 'solution', 'quoted', 'negotiating'];

    private const STAGE_LABELS = [
        'discovery' => '初步接洽',
        'qualified' => '已确认需求',
        'solution' => '方案阶段',
        'quoted' => '已报价',
        'negotiating' => '谈判中',
        'won' => '成交',
        'lost' => '丢单',
    ];

    private const CHANNELS = ['note', 'email', 'whatsapp', 'call', 'meeting'];

    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly AuditRecorder $audit,
    ) {}

    public function project(string $projectKey): object
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        return $project;
    }

    public function list(string $projectKey, array $filters = []): Collection
    {
        $project = $this->project($projectKey);
        $opportunityClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesOpportunity';

        return $opportunityClass::query()
            ->with(['company:id,name,country_code,identity_status', 'contact:id,name,email,phone', 'owner:id,name,email'])
            ->withCount('quotes')
            ->where('business_project_id', $project->id)
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['stage'] ?? null, fn ($q, $value) => $q->where('pipeline_stage', $value))
            ->when($filters['owner_id'] ?? null, fn ($q, $value) => $q->where('owner_id', $value))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('opportunity_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('company', fn ($company) => $company->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'won' THEN 1 ELSE 2 END")
            ->orderByRaw('next_follow_up_at IS NULL')
            ->orderBy('next_follow_up_at')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function metrics(string $projectKey, ?int $ownerId = null): array
    {
        $project = $this->project($projectKey);
        $opportunityClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesOpportunity';
        $base = $opportunityClass::query()->where('business_project_id', $project->id)
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId));

        return [
            'open' => (clone $base)->where('status', 'open')->count(),
            'quoted' => (clone $base)->where('status', 'open')->whereIn('pipeline_stage', ['quoted', 'negotiating'])->count(),
            'won' => (clone $base)->where('status', 'won')->count(),
            'lost' => (clone $base)->where('status', 'lost')->count(),
            'pipeline_value_micros' => (int) (clone $base)->where('status', 'open')->sum('expected_value_micros'),
        ];
    }

    public function create(string $projectKey, array $attributes, ?Authenticatable $actor): Model
    {
        $project = $this->project($projectKey);
        $companyClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\Company';
        $customerClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\Customer';
        $caseClass = 'App\\Modules\\Inquiries\\Infrastructure\\Models\\InquiryCase';
        $opportunityClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesOpportunity';

        $company = $companyClass::query()->findOrFail((int) $attributes['company_id']);
        $contactId = isset($attributes['contact_id']) && $attributes['contact_id'] !== '' ? (int) $attributes['contact_id'] : null;
        if ($contactId !== null && ! $customerClass::query()->whereKey($contactId)->whereHas('companies', fn ($q) => $q->whereKey($company->id))->exists()) {
            throw new InvalidArgumentException('Contact is not linked to the selected company.');
        }

        $caseId = isset($attributes['case_id']) && $attributes['case_id'] !== '' ? (int) $attributes['case_id'] : null;
        $case = null;
        if ($caseId !== null) {
            $case = $caseClass::query()->where('business_project_id', $project->id)->findOrFail($caseId);
            if ($case->company_id === null || (int) $case->company_id !== (int) $company->id) {
                throw new InvalidArgumentException('Inquiry Case must already be resolved to the selected company.');
            }
            $existing = $opportunityClass::query()->where('business_project_id', $project->id)->where('case_id', $caseId)->first();
            if ($existing) {
                return $existing->load(['company', 'contact', 'owner']);
            }
        }

        $stage = $attributes['pipeline_stage'] ?? 'discovery';
        $this->assertStage($stage);

        return DB::transaction(function () use ($opportunityClass, $project, $company, $contactId, $case, $attributes, $stage, $actor): Model {
            $opportunity = $opportunityClass::create([
                'opportunity_number' => $this->number('OPP'),
                'business_project_id' => $project->id,
                'case_id' => $case?->id,
                'company_id' => $company->id,
                'contact_id' => $contactId,
                'owner_id' => $attributes['owner_id'] ?? $case?->owner_id ?? $company->owner_id,
                'title' => trim((string) $attributes['title']),
                'status' => 'open',
                'pipeline_stage' => $stage,
                'currency' => strtoupper((string) ($attributes['currency'] ?? 'USD')),
                'expected_value_micros' => $this->nullableInt($attributes['expected_value_micros'] ?? null),
                'probability_bps' => $this->stageProbability($stage),
                'expected_close_date' => $attributes['expected_close_date'] ?? null,
                'next_follow_up_at' => $attributes['next_follow_up_at'] ?? null,
                'attribution' => $case?->attribution,
            ]);
            $this->recordStageTransition($opportunity, null, $stage, $actor, 'create', 'opportunity_created');
            $this->record($actor, 'sales.opportunity.created', $opportunity, null, $this->snapshot($opportunity), 'opportunity_created');

            return $opportunity->load(['company', 'contact', 'owner']);
        });
    }

    public function find(string $projectKey, int $opportunityId): Model
    {
        $project = $this->project($projectKey);
        $opportunityClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesOpportunity';

        return $opportunityClass::query()
            ->with(['company', 'contact', 'owner', 'case', 'followUps.actor', 'followUps.attachments.uploader', 'stageEvents.actor', 'quotes'])
            ->where('business_project_id', $project->id)
            ->findOrFail($opportunityId);
    }

    public function roadmap(string $projectKey, int $opportunityId): array
    {
        $opportunity = $this->find($projectKey, $opportunityId);
        $currentStage = $opportunity->status === 'open' ? (string) $opportunity->pipeline_stage : (string) $opportunity->status;
        $currentIndex = array_search($currentStage, self::ROADMAP_STAGES, true);
        $closed = in_array($opportunity->status, ['won', 'lost'], true);
        $now = $opportunity->won_at ?? $opportunity->lost_at ?? now();

        $nodes = collect(self::ROADMAP_STAGES)->map(function (string $stage, int $index) use ($opportunity, $currentIndex, $closed, $now): array {
            $events = $opportunity->stageEvents->where('to_stage', $stage)->values();
            $latestEvent = $events->last();
            $enteredAt = $latestEvent?->entered_at;
            $nextEvent = $enteredAt
                ? $opportunity->stageEvents->first(fn ($event) => $event->entered_at?->greaterThan($enteredAt))
                : null;
            $stageFollowUps = $opportunity->followUps->where('stage_key', $stage);

            $state = 'pending';
            if ($currentIndex !== false && $index === $currentIndex && ! $closed) {
                $state = 'current';
            } elseif ($events->isNotEmpty()) {
                // Lifetime overlay: once a stage was genuinely entered, reopening to an
                // earlier stage must never make that historical stage look unvisited.
                $state = 'completed';
            } elseif ($closed || ($currentIndex !== false && $index < $currentIndex)) {
                $state = 'skipped';
            }

            return [
                'key' => $stage,
                'label' => self::STAGE_LABELS[$stage],
                'state' => $state,
                'entered_at' => $enteredAt?->toIso8601String(),
                'exited_at' => $nextEvent?->entered_at?->toIso8601String(),
                'duration_days' => $enteredAt ? max(0, (int) floor($enteredAt->diffInDays($nextEvent?->entered_at ?? $now))) : null,
                'note_count' => $stageFollowUps->count(),
                'attachment_count' => $stageFollowUps->sum(fn ($followUp): int => $followUp->attachments->count()),
            ];
        })->values();

        $finalStage = $closed ? (string) $opportunity->status : null;
        $finalFollowUps = $finalStage ? $opportunity->followUps->where('stage_key', $finalStage) : collect();
        $finalEvent = $finalStage ? $opportunity->stageEvents->where('to_stage', $finalStage)->last() : null;
        $nodes->push([
            'key' => $finalStage ?? 'final',
            'label' => $finalStage ? self::STAGE_LABELS[$finalStage] : '成交 / 丢单',
            'state' => $closed ? 'current' : 'pending',
            'entered_at' => $finalEvent?->entered_at?->toIso8601String(),
            'exited_at' => null,
            'duration_days' => $finalEvent?->entered_at ? max(0, (int) floor($finalEvent->entered_at->diffInDays($now))) : null,
            'note_count' => $finalFollowUps->count(),
            'attachment_count' => $finalFollowUps->sum(fn ($followUp): int => $followUp->attachments->count()),
        ]);

        $nextStage = null;
        if (! $closed && $currentIndex !== false && $currentIndex < count(self::ROADMAP_STAGES) - 1) {
            $nextStage = self::ROADMAP_STAGES[$currentIndex + 1];
        }

        return [
            'current_stage' => $currentStage,
            'current_label' => self::STAGE_LABELS[$currentStage] ?? $currentStage,
            'next_stage' => $nextStage,
            'next_label' => $nextStage ? self::STAGE_LABELS[$nextStage] : null,
            'closed' => $closed,
            'total_elapsed_days' => max(0, (int) floor($opportunity->created_at->diffInDays($now))),
            'nodes' => $nodes->all(),
            'events' => $opportunity->stageEvents->map(fn ($event): array => [
                'id' => $event->id,
                'from_stage' => $event->from_stage,
                'to_stage' => $event->to_stage,
                'to_label' => self::STAGE_LABELS[$event->to_stage] ?? $event->to_stage,
                'entered_at' => $event->entered_at?->toIso8601String(),
                'source' => $event->source,
                'reason' => $event->reason,
                'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
            ])->values()->all(),
        ];
    }

    public function updateStage(string $projectKey, int $opportunityId, string $stage, ?Authenticatable $actor): Model
    {
        $this->assertStage($stage);

        return DB::transaction(function () use ($projectKey, $opportunityId, $stage, $actor): Model {
            $opportunity = $this->find($projectKey, $opportunityId);
            if ($opportunity->status !== 'open') {
                throw new InvalidArgumentException('Closed opportunity cannot change pipeline stage.');
            }
            $before = $this->snapshot($opportunity);
            $fromStage = (string) $opportunity->pipeline_stage;
            if ($fromStage === $stage) {
                return $opportunity;
            }
            $opportunity->forceFill(['pipeline_stage' => $stage, 'probability_bps' => $this->stageProbability($stage)])->save();
            $this->recordStageTransition($opportunity, $fromStage, $stage, $actor, 'manual', 'pipeline_stage_updated');
            $this->record($actor, 'sales.opportunity.stage_updated', $opportunity, $before, $this->snapshot($opportunity), 'pipeline_stage_updated');

            return $opportunity->fresh(['company', 'contact', 'owner']);
        });
    }

    public function addFollowUp(string $projectKey, int $opportunityId, array $attributes, ?Authenticatable $actor): Model
    {
        $channel = strtolower((string) ($attributes['channel'] ?? 'note'));
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unsupported follow-up channel.');
        }

        return DB::transaction(function () use ($projectKey, $opportunityId, $attributes, $channel, $actor): Model {
            $opportunity = $this->find($projectKey, $opportunityId);
            $followUpClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesFollowUp';
            $happenedAt = $attributes['happened_at'] ?? now();
            $stageKey = (string) ($attributes['stage_key'] ?? $opportunity->pipeline_stage);
            $this->assertRoadmapStage($stageKey);
            $followUp = $followUpClass::create([
                'opportunity_id' => $opportunity->id,
                'stage_key' => $stageKey,
                'actor_id' => $actor?->getAuthIdentifier(),
                'channel' => $channel,
                'outcome' => $attributes['outcome'] ?? null,
                'summary' => trim((string) $attributes['summary']),
                'next_plan' => $this->nullableText($attributes['next_plan'] ?? null),
                'happened_at' => $happenedAt,
                'next_follow_up_at' => $attributes['next_follow_up_at'] ?? null,
            ]);
            foreach (($attributes['attachments'] ?? []) as $file) {
                if ($file instanceof UploadedFile) {
                    $this->storeFollowUpAttachment($followUp, $file, $actor);
                }
            }
            $opportunity->forceFill([
                'last_follow_up_at' => $happenedAt,
                'next_follow_up_at' => $attributes['next_follow_up_at'] ?? $opportunity->next_follow_up_at,
            ])->save();
            $this->record($actor, 'sales.follow_up.created', $followUp, null, [
                'opportunity_id' => $opportunity->id,
                'stage_key' => $stageKey,
                'channel' => $channel,
                'attachment_count' => $followUp->attachments()->count(),
            ], 'follow_up_created');

            return $followUp->load(['actor', 'attachments.uploader']);
        });
    }

    public function createQuote(string $projectKey, int $opportunityId, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($projectKey, $opportunityId, $attributes, $actor): Model {
            $opportunity = $this->find($projectKey, $opportunityId);
            if ($opportunity->status !== 'open') {
                throw new InvalidArgumentException('Closed opportunity cannot receive a new quote.');
            }
            $quoteClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesQuote';
            $version = (int) $quoteClass::query()->where('opportunity_id', $opportunity->id)->max('version') + 1;
            $subtotal = max(0, (int) ($attributes['subtotal_micros'] ?? 0));
            $discount = max(0, (int) ($attributes['discount_micros'] ?? 0));
            $tax = max(0, (int) ($attributes['tax_micros'] ?? 0));
            $total = max(0, $subtotal - $discount + $tax);
            $quote = $quoteClass::create([
                'quote_number' => $this->number('Q'),
                'opportunity_id' => $opportunity->id,
                'version' => $version,
                'status' => 'draft',
                'currency' => strtoupper((string) ($attributes['currency'] ?? $opportunity->currency)),
                'subtotal_micros' => $subtotal,
                'discount_micros' => $discount,
                'tax_micros' => $tax,
                'total_micros' => $total,
                'valid_until' => $attributes['valid_until'] ?? null,
                'line_items' => $attributes['line_items'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);
            if (! in_array($opportunity->pipeline_stage, ['quoted', 'negotiating'], true)) {
                $fromStage = (string) $opportunity->pipeline_stage;
                $opportunity->forceFill(['pipeline_stage' => 'quoted', 'probability_bps' => $this->stageProbability('quoted')])->save();
                $this->recordStageTransition($opportunity, $fromStage, 'quoted', $actor, 'quote', 'quote_created');
            }
            $this->record($actor, 'sales.quote.created', $quote, null, ['opportunity_id' => $opportunity->id, 'version' => $version, 'total_micros' => $total], 'quote_created');

            return $quote;
        });
    }

    public function markWon(string $projectKey, int $opportunityId, ?Authenticatable $actor): Model
    {
        return $this->close($projectKey, $opportunityId, 'won', null, null, $actor);
    }

    public function markLost(string $projectKey, int $opportunityId, string $reasonCode, ?string $reasonNote, ?Authenticatable $actor): Model
    {
        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('Lost reason is required.');
        }

        return $this->close($projectKey, $opportunityId, 'lost', trim($reasonCode), $reasonNote, $actor);
    }

    public function reopen(string $projectKey, int $opportunityId, string $stage, string $reason, ?Authenticatable $actor): Model
    {
        $this->assertStage($stage);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reopen reason is required.');
        }

        return DB::transaction(function () use ($projectKey, $opportunityId, $stage, $reason, $actor): Model {
            $opportunity = $this->find($projectKey, $opportunityId);
            if ($opportunity->status !== 'lost') {
                throw new InvalidArgumentException('Only lost opportunities can be reopened.');
            }

            $before = $this->snapshot($opportunity);
            $opportunity->forceFill([
                'status' => 'open',
                'pipeline_stage' => $stage,
                'probability_bps' => $this->stageProbability($stage),
                'won_at' => null,
                'lost_at' => null,
                'lost_reason_code' => null,
                'lost_reason_note' => null,
            ])->save();

            $this->recordStageTransition($opportunity, 'lost', $stage, $actor, 'reopen', $reason);
            $this->record($actor, 'sales.opportunity.reopened', $opportunity, $before, $this->snapshot($opportunity), 'opportunity_reopened');

            return $opportunity->fresh(['company', 'contact', 'owner']);
        });
    }

    private function close(string $projectKey, int $opportunityId, string $status, ?string $reasonCode, ?string $reasonNote, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($projectKey, $opportunityId, $status, $reasonCode, $reasonNote, $actor): Model {
            $opportunity = $this->find($projectKey, $opportunityId);
            if ($opportunity->status === $status) {
                return $opportunity;
            }
            if ($opportunity->status !== 'open') {
                throw new InvalidArgumentException('Opportunity is already closed.');
            }
            $before = $this->snapshot($opportunity);
            $fromStage = (string) $opportunity->pipeline_stage;
            $now = now();
            $opportunity->forceFill([
                'status' => $status,
                'pipeline_stage' => $status,
                'probability_bps' => $status === 'won' ? 10000 : 0,
                'won_at' => $status === 'won' ? $now : null,
                'lost_at' => $status === 'lost' ? $now : null,
                'lost_reason_code' => $status === 'lost' ? $reasonCode : null,
                'lost_reason_note' => $status === 'lost' ? $reasonNote : null,
                'next_follow_up_at' => null,
            ])->save();

            if ($status === 'won') {
                $projectCompanyClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\BusinessProjectCompany';
                $projectCompanyClass::query()
                    ->where('business_project_id', $opportunity->business_project_id)
                    ->where('company_id', $opportunity->company_id)
                    ->update(['relationship_status' => 'customer', 'last_won_at' => $now, 'updated_at' => $now]);
            }

            $transitionReason = $status === 'lost'
                ? trim((string) $reasonCode.($reasonNote ? ' · '.$reasonNote : ''))
                : 'opportunity_won';
            $this->recordStageTransition($opportunity, $fromStage, $status, $actor, 'close', $transitionReason);
            $this->record($actor, 'sales.opportunity.'.$status, $opportunity, $before, $this->snapshot($opportunity), 'opportunity_'.$status);

            return $opportunity->fresh(['company', 'contact', 'owner']);
        });
    }

    public function findFollowUpAttachment(string $projectKey, int $opportunityId, int $followUpId, int $attachmentId): Model
    {
        $opportunity = $this->find($projectKey, $opportunityId);
        $followUp = $opportunity->followUps->firstWhere('id', $followUpId);
        if (! $followUp) {
            abort(404);
        }

        $attachment = $followUp->attachments->firstWhere('id', $attachmentId);
        if (! $attachment) {
            abort(404);
        }

        return $attachment;
    }

    private function recordStageTransition(Model $opportunity, ?string $fromStage, string $toStage, ?Authenticatable $actor, string $source, ?string $reason): void
    {
        $eventClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesOpportunityStageEvent';
        $eventClass::create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => $actor?->getAuthIdentifier(),
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'entered_at' => now(),
            'source' => $source,
            'reason' => $reason,
        ]);
    }

    private function storeFollowUpAttachment(Model $followUp, UploadedFile $file, ?Authenticatable $actor): Model
    {
        $attachmentClass = 'App\\Modules\\Sales\\Infrastructure\\Models\\SalesFollowUpAttachment';
        $disk = (string) config('filesystems.default', 'local');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $name = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = 'sales/opportunity-roadmap/'.$followUp->opportunity_id.'/'.$followUp->id.'/'.$name;
        $stored = Storage::disk($disk)->putFileAs(
            'sales/opportunity-roadmap/'.$followUp->opportunity_id.'/'.$followUp->id,
            $file,
            $name,
        );
        if (! $stored) {
            throw new \RuntimeException('Failed to store sales follow-up attachment.');
        }

        return $attachmentClass::create([
            'follow_up_id' => $followUp->id,
            'uploaded_by' => $actor?->getAuthIdentifier(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ]);
    }

    private function assertRoadmapStage(string $stage): void
    {
        if (! in_array($stage, [...self::ROADMAP_STAGES, 'won', 'lost'], true)) {
            throw new InvalidArgumentException('Unsupported roadmap stage.');
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertStage(string $stage): void
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Unsupported pipeline stage.');
        }
    }

    private function stageProbability(string $stage): int
    {
        return match ($stage) {
            'discovery' => 1000,
            'qualified' => 3000,
            'solution' => 5000,
            'quoted' => 6500,
            'negotiating' => 8000,
            default => 0,
        };
    }

    private function number(string $kind): string
    {
        return 'YBY-'.$kind.'-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function snapshot(Model $opportunity): array
    {
        return $opportunity->only(['business_project_id', 'case_id', 'company_id', 'contact_id', 'owner_id', 'status', 'pipeline_stage', 'currency', 'expected_value_micros', 'probability_bps', 'expected_close_date', 'next_follow_up_at', 'won_at', 'lost_at', 'lost_reason_code', 'lost_reason_note']);
    }

    private function record(?Authenticatable $actor, string $action, Model $entity, ?array $before, ?array $after, string $reason): void
    {
        $auditActor = $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem);
        $this->audit->record($auditActor, $action, get_class($entity), $entity->getKey(), $before, $after, $reason);
    }
}
