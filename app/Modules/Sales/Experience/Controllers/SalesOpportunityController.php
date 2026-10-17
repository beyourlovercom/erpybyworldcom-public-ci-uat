<?php

namespace App\Modules\Sales\Experience\Controllers;

use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Application\ReceivableStatusService;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Application\SalesOwnerScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SalesOpportunityController
{
    public function __construct(
        private readonly SalesOpportunityService $sales,
        private readonly PaymentReceiptService $payments,
        private readonly ReceivableStatusService $receivables,
        private readonly SalesOwnerScope $ownerScope,
    ) {}

    public function index(Request $request, string $projectKey): Response
    {
        $project = $this->sales->project($projectKey);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,won,lost'],
            'stage' => ['nullable', 'in:discovery,qualified,solution,quoted,negotiating'],
            'owner_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $restrictedOwnerId = $this->ownerScope->restrictedOwnerId($request->user());
        if ($restrictedOwnerId !== null) {
            abort_if(isset($filters['owner_id']) && (int) $filters['owner_id'] !== $restrictedOwnerId, 403);
            $filters['owner_id'] = $restrictedOwnerId;
        }
        $opportunities = $this->sales->list($projectKey, $filters);
        $relationships = BusinessProjectCompany::query()
            ->with('company:id,name,country_code,identity_status')
            ->where('business_project_id', $project->id)
            ->when($restrictedOwnerId !== null, fn ($query) => $query->where('owner_id', $restrictedOwnerId))
            ->whereIn('relationship_status', ['prospect', 'customer'])
            ->orderByDesc('last_inquiry_at')
            ->get();
        $owners = User::query()->where('is_active', true)
            ->when($restrictedOwnerId !== null, fn ($query) => $query->whereKey($restrictedOwnerId))
            ->orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('Sales/Index', [
            'project' => ['id' => $project->id, 'key' => $project->project_key, 'name' => $project->name],
            'metrics' => $this->sales->metrics($projectKey, $restrictedOwnerId),
            'filters' => $filters,
            'owners' => $owners,
            'companies' => $relationships->map(fn ($row) => [
                'id' => $row->company_id,
                'name' => $row->company?->name,
                'identity_status' => $row->company?->identity_status,
                'relationship_status' => $row->relationship_status,
            ])->values(),
            'opportunities' => $opportunities->map(fn ($row) => $this->summary($row))->values(),
        ]);
    }

    public function receipts(Request $request, string $projectKey): Response
    {
        $project = $this->sales->project($projectKey);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'in:confirmed,voided'],
            'currency' => ['nullable', 'string', 'size:3'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $dateTo = (string) ($validated['date_to'] ?? CarbonImmutable::today()->toDateString());
        $dateFrom = (string) ($validated['date_from'] ?? CarbonImmutable::parse($dateTo)->subDays(29)->toDateString());
        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $validated['status'] ?? null,
            'currency' => isset($validated['currency']) ? strtoupper((string) $validated['currency']) : null,
            'search' => $validated['search'] ?? null,
        ];

        $restrictedOwnerId = $this->ownerScope->restrictedOwnerId($request->user());
        if ($restrictedOwnerId !== null) {
            $filters['owner_id'] = $restrictedOwnerId;
        }
        $receipts = $this->payments->ledger($projectKey, $filters);
        $auditTrail = $this->payments->auditTrail($receipts);

        return Inertia::render('Sales/Receipts', [
            'project' => ['id' => $project->id, 'key' => $project->project_key, 'name' => $project->name],
            'filters' => $filters,
            'confirmed_totals' => $this->payments->confirmedTotalsForProject($projectKey, $filters),
            'receipts' => $receipts->map(fn ($receipt) => [
                'id' => (int) $receipt->id,
                'receipt_number' => (string) $receipt->receipt_number,
                'amount_micros' => (int) $receipt->amount_micros,
                'currency' => (string) $receipt->currency,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'payment_method' => (string) $receipt->payment_method,
                'entry_source' => (string) $receipt->entry_source,
                'provider_reference' => $receipt->provider_reference,
                'evidence_reference' => $receipt->evidence_reference,
                'note' => $receipt->note,
                'status' => (string) $receipt->status,
                'company' => $receipt->company ? ['id' => (int) $receipt->company->id, 'name' => (string) $receipt->company->name] : null,
                'opportunity' => $receipt->opportunity ? [
                    'id' => (int) $receipt->opportunity->id,
                    'opportunity_number' => (string) $receipt->opportunity->opportunity_number,
                    'title' => (string) $receipt->opportunity->title,
                ] : null,
                'recorded_by' => $receipt->recorder ? ['id' => (int) $receipt->recorder->id, 'name' => (string) $receipt->recorder->name] : null,
                'voided_at' => $receipt->voided_at?->toIso8601String(),
                'void_reason' => $receipt->void_reason,
                'voided_by' => $receipt->voider ? ['id' => (int) $receipt->voider->id, 'name' => (string) $receipt->voider->name] : null,
                'audit_events' => $auditTrail[(int) $receipt->id] ?? [],
            ])->values(),
        ]);
    }

    public function store(Request $request, string $projectKey): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'case_id' => ['nullable', 'integer', 'exists:cases,id'],
            'contact_id' => ['nullable', 'integer', 'exists:customers,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'pipeline_stage' => ['nullable', 'in:discovery,qualified,solution,quoted,negotiating'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_value_micros' => ['nullable', 'integer', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);
        $this->ownerScope->enforceCreation($projectKey, $data, $request->user());
        $opportunity = $this->sales->create($projectKey, $data, $request->user());

        return redirect()->route('sales.opportunities.show', [$projectKey, $opportunity->id]);
    }

    public function show(Request $request, string $projectKey, int $opportunity): Response
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $row = $this->sales->find($projectKey, $opportunity);
        $receipts = $this->payments->listForOpportunity($projectKey, $opportunity);
        $paymentTotals = $this->payments->confirmedTotalsForOpportunity($projectKey, $opportunity);
        $receivable = $this->receivables->summary($projectKey, $opportunity);
        $roadmap = $this->sales->roadmap($projectKey, $opportunity);

        return Inertia::render('Sales/Show', [
            'project' => ['key' => $row->project->project_key, 'name' => $row->project->name],
            'opportunity' => [
                ...$this->summary($row),
                'case' => $row->case ? ['id' => $row->case->id, 'case_number' => $row->case->case_number] : null,
                'contact' => $row->contact ? ['id' => $row->contact->id, 'name' => $row->contact->name, 'email' => $row->contact->email, 'phone' => $row->contact->phone] : null,
                'lost_reason_code' => $row->lost_reason_code,
                'lost_reason_note' => $row->lost_reason_note,
                'follow_ups' => $row->followUps->map(fn ($item) => [
                    'id' => $item->id,
                    'stage_key' => $item->stage_key,
                    'channel' => $item->channel,
                    'outcome' => $item->outcome,
                    'summary' => $item->summary,
                    'next_plan' => $item->next_plan,
                    'happened_at' => $item->happened_at?->toIso8601String(),
                    'next_follow_up_at' => $item->next_follow_up_at?->toIso8601String(),
                    'actor' => $item->actor ? ['id' => $item->actor->id, 'name' => $item->actor->name] : null,
                    'attachments' => $item->attachments->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'url' => route('sales.opportunities.follow-ups.attachments.show', [
                            'projectKey' => $projectKey,
                            'opportunity' => $row->id,
                            'followUp' => $item->id,
                            'attachment' => $attachment->id,
                        ], false),
                    ])->values(),
                ])->values(),
                'quotes' => $row->quotes->map(fn ($quote) => [
                    'id' => $quote->id, 'quote_number' => $quote->quote_number, 'version' => $quote->version,
                    'status' => $quote->status, 'currency' => $quote->currency, 'subtotal_micros' => $quote->subtotal_micros,
                    'discount_micros' => $quote->discount_micros, 'tax_micros' => $quote->tax_micros,
                    'total_micros' => $quote->total_micros, 'valid_until' => $quote->valid_until?->toDateString(),
                ])->values(),
            ],
            'payment_receipts' => $receipts->map(fn ($receipt) => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'amount_micros' => $receipt->amount_micros,
                'currency' => $receipt->currency,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'payment_method' => $receipt->payment_method,
                'entry_source' => $receipt->entry_source,
                'provider_reference' => $receipt->provider_reference,
                'evidence_reference' => $receipt->evidence_reference,
                'note' => $receipt->note,
                'status' => $receipt->status,
                'recorded_by' => $receipt->recorder ? ['id' => $receipt->recorder->id, 'name' => $receipt->recorder->name] : null,
                'voided_at' => $receipt->voided_at?->toIso8601String(),
                'void_reason' => $receipt->void_reason,
                'voided_by' => $receipt->voider ? ['id' => $receipt->voider->id, 'name' => $receipt->voider->name] : null,
            ])->values(),
            'payment_totals' => $paymentTotals,
            'receivable' => $receivable,
            'roadmap' => $roadmap,
        ]);
    }

    public function stage(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate(['pipeline_stage' => ['required', 'in:discovery,qualified,solution,quoted,negotiating']]);
        $this->sales->updateStage($projectKey, $opportunity, $data['pipeline_stage'], $request->user());

        return back();
    }

    public function followUp(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'stage_key' => ['required', 'in:discovery,qualified,solution,quoted,negotiating,won,lost'],
            'channel' => ['required', 'in:note,email,whatsapp,call,meeting'],
            'outcome' => ['nullable', 'string', 'max:64'],
            'summary' => ['required', 'string', 'max:5000'],
            'next_plan' => ['nullable', 'string', 'max:5000'],
            'happened_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ]);
        $this->sales->addFollowUp($projectKey, $opportunity, $data, $request->user());

        return back();
    }

    public function followUpAttachment(Request $request, string $projectKey, int $opportunity, int $followUp, int $attachment): StreamedResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $item = $this->sales->findFollowUpAttachment($projectKey, $opportunity, $followUp, $attachment);
        abort_unless(Storage::disk($item->disk)->exists($item->path), 404);

        $headers = $item->mime_type ? ['Content-Type' => $item->mime_type] : [];

        return Storage::disk($item->disk)->response($item->path, $item->original_name, $headers, 'inline');
    }

    public function quote(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'subtotal_micros' => ['required', 'integer', 'min:0'],
            'discount_micros' => ['nullable', 'integer', 'min:0'],
            'tax_micros' => ['nullable', 'integer', 'min:0'],
            'valid_until' => ['nullable', 'date'],
            'line_items' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->sales->createQuote($projectKey, $opportunity, $data, $request->user());

        return back();
    }

    public function receivable(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'source' => ['required', 'in:manual,quote'],
            'amount_micros' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'quote_id' => ['nullable', 'integer', 'exists:sales_quotes,id'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['source'] === 'quote') {
            $request->validate(['quote_id' => ['required', 'integer', 'exists:sales_quotes,id']]);
            $this->receivables->confirmFromQuote(
                $projectKey,
                $opportunity,
                (int) $data['quote_id'],
                $data['note'] ?? null,
                $request->user(),
            );
        } else {
            $request->validate([
                'amount_micros' => ['required', 'integer', 'min:1'],
                'currency' => ['required', 'string', 'size:3'],
            ]);
            $this->receivables->confirmManual(
                $projectKey,
                $opportunity,
                (int) $data['amount_micros'],
                (string) $data['currency'],
                $data['note'] ?? null,
                $request->user(),
            );
        }

        return back();
    }

    public function paymentReceipt(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'amount_micros' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'received_at' => ['required', 'date'],
            'payment_method' => ['required', 'in:bank_transfer,alipay,alibaba,paypal,stripe,other'],
            'provider_reference' => ['nullable', 'string', 'max:191'],
            'evidence_reference' => ['nullable', 'string', 'max:2048'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->payments->record($projectKey, $opportunity, $data, $request->user());

        return back();
    }

    public function voidPaymentReceipt(Request $request, string $projectKey, int $opportunity, int $receipt): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->payments->voidForOpportunity($projectKey, $opportunity, $receipt, $data['void_reason'], $request->user());

        return back();
    }

    public function won(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $this->sales->markWon($projectKey, $opportunity, $request->user());

        return back();
    }

    public function lost(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'lost_reason_code' => ['required', 'string', 'max:64'],
            'lost_reason_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->sales->markLost($projectKey, $opportunity, $data['lost_reason_code'], $data['lost_reason_note'] ?? null, $request->user());

        return back();
    }

    public function reopen(Request $request, string $projectKey, int $opportunity): RedirectResponse
    {
        $this->ownerScope->assertOpportunity($projectKey, $opportunity, $request->user());
        $data = $request->validate([
            'pipeline_stage' => ['required', 'in:discovery,qualified,solution,quoted,negotiating'],
            'reopen_reason' => ['required', 'string', 'max:1000'],
        ]);
        $this->sales->reopen($projectKey, $opportunity, $data['pipeline_stage'], $data['reopen_reason'], $request->user());

        return back();
    }

    private function summary(object $row): array
    {
        return [
            'id' => $row->id,
            'opportunity_number' => $row->opportunity_number,
            'title' => $row->title,
            'status' => $row->status,
            'pipeline_stage' => $row->pipeline_stage,
            'currency' => $row->currency,
            'expected_value_micros' => $row->expected_value_micros,
            'probability_bps' => $row->probability_bps,
            'expected_close_date' => $row->expected_close_date?->toDateString(),
            'next_follow_up_at' => $row->next_follow_up_at?->toIso8601String(),
            'last_follow_up_at' => $row->last_follow_up_at?->toIso8601String(),
            'lost_at' => $row->lost_at?->toIso8601String(),
            'lost_reason_code' => $row->lost_reason_code,
            'lost_reason_note' => $row->lost_reason_note,
            'company' => $row->company ? ['id' => $row->company->id, 'name' => $row->company->name, 'country_code' => $row->company->country_code, 'identity_status' => $row->company->identity_status] : null,
            'owner' => $row->owner ? ['id' => $row->owner->id, 'name' => $row->owner->name] : null,
            'quotes_count' => $row->quotes_count ?? $row->quotes?->count() ?? 0,
        ];
    }
}
