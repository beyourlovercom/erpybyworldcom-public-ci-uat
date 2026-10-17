<?php

namespace App\Modules\Inquiries\Experience\Console;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Inquiries\Application\InquiryIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncLocalWordPressInquiriesCommand extends Command
{
    protected $signature = 'inquiries:wordpress:sync-local
        {--site= : Fallback registered site/domain}
        {--limit=200 : Maximum source rows}
        {--apply : Persist Canonical Case rows}';

    protected $description = 'Read YBY Core WordPress leads and project them into local YBY ERP Canonical Case truth.';

    public function handle(InquiryIntakeService $intake, BusinessProjectService $projects): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This adapter is restricted to local/testing environments.');
            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $prefix = (string) env('YBY_WP_DB_PREFIX', 'wp_');
        $fallbackSite = trim((string) $this->option('site'));
        $apply = (bool) $this->option('apply');

        try {
            $rows = DB::connection('yby_wordpress')
                ->table($prefix.'yby_leads')
                ->orderBy('created_at')
                ->limit($limit)
                ->get();
        } catch (Throwable $exception) {
            $this->error('WordPress source read failed: '.$exception->getMessage());
            return self::FAILURE;
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry_run', 'seen' => 0, 'eligible' => 0,
            'created' => 0, 'noop' => 0, 'skipped_unregistered_site' => 0, 'blocked' => [],
        ];

        foreach ($rows as $row) {
            $summary['seen']++;
            $site = trim((string) ($row->website ?? '')) ?: $fallbackSite;
            if ($site === '' || ! $projects->findSiteByReference($site)) {
                $summary['skipped_unregistered_site']++;
                continue;
            }
            $summary['eligible']++;
            $input = $this->mapRow($row, $site);

            if (! $apply) {
                continue;
            }

            try {
                $result = $intake->ingest($input);
                $result['created'] ? $summary['created']++ : $summary['noop']++;
            } catch (Throwable $exception) {
                $summary['blocked'][] = [
                    'source_external_id' => (string) ($row->case_id ?? ''),
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $summary['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function mapRow(object $row, string $site): array
    {
        $attribution = array_filter([
            'utm_source' => $row->utm_source ?? null,
            'utm_medium' => $row->utm_medium ?? null,
            'utm_campaign' => $row->utm_campaign ?? null,
            'utm_term' => $row->utm_term ?? null,
            'gclid' => $row->gclid ?? null,
            'fbclid' => $row->fbclid ?? null,
            'source_url' => $row->source_url ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            'source_channel' => 'website',
            'site' => $site,
            'source_external_id' => (string) $row->case_id,
            'contact_name' => $row->name ?? null,
            'contact_email' => $row->email ?? null,
            'contact_phone' => $row->whatsapp ?? null,
            'company_name' => $row->company ?? null,
            'country' => $row->country ?? null,
            'buyer_type' => $row->buyer_type ?? null,
            'product_interest' => $row->product_interest ?? null,
            'quantity' => $row->quantity ?? null,
            'first_inbound_at' => (string) $row->created_at,
            'last_inbound_at' => (string) $row->created_at,
            'attribution' => $attribution,
            'source_metadata' => [
                'brand' => $row->brand ?? null,
                'source_component' => $row->source_component ?? null,
                'source_preset' => $row->source_preset ?? null,
                'source_page' => $row->source_page ?? null,
                'form_version' => $row->form_version ?? null,
                'page_profile' => $row->page_profile ?? null,
                'status' => $row->status ?? null,
            ],
        ];
    }
}