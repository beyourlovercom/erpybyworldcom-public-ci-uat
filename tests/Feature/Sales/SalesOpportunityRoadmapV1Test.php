<?php

namespace Tests\Feature\Sales;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Infrastructure\Models\SalesFollowUp;
use App\Modules\Sales\Infrastructure\Models\SalesFollowUpAttachment;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunityStageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SalesOpportunityRoadmapV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_stage_history_follow_up_attachments_and_auto_transitions_drive_roadmap_truth(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
        $owner = User::factory()->create(['name' => 'Roadmap Owner', 'is_active' => true]);
        $company = Company::create([
            'name' => 'Roadmap Customer',
            'status' => 'prospect',
            'identity_status' => 'verified',
        ]);
        BusinessProjectCompany::create([
            'business_project_id' => $project->id,
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'relationship_status' => 'prospect',
            'lifetime_revenue_micros' => 0,
        ]);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'title' => 'Roadmap irrigation opportunity',
            'currency' => 'USD',
            'pipeline_stage' => 'discovery',
        ], $owner);

        $this->assertSame(1, SalesOpportunityStageEvent::count());
        $initial = SalesOpportunityStageEvent::firstOrFail();
        $this->assertNull($initial->from_stage);
        $this->assertSame('discovery', $initial->to_stage);
        $this->assertSame('create', $initial->source);

        $sales->updateStage($project->project_key, $opportunity->id, 'qualified', $owner);
        $this->assertSame(['discovery', 'qualified'], SalesOpportunityStageEvent::query()->orderBy('id')->pluck('to_stage')->all());

        $followUp = $sales->addFollowUp($project->project_key, $opportunity->id, [
            'stage_key' => 'qualified',
            'channel' => 'whatsapp',
            'summary' => 'Customer confirmed 16mm quick-connect irrigation requirements.',
            'next_plan' => 'Prepare solution and PI draft.',
            'next_follow_up_at' => now()->addDays(2),
            'attachments' => [
                UploadedFile::fake()->image('customer-site.jpg', 900, 600),
                UploadedFile::fake()->create('specification.pdf', 120, 'application/pdf'),
            ],
        ], $owner);

        $this->assertSame('qualified', $followUp->stage_key);
        $this->assertSame('Prepare solution and PI draft.', $followUp->next_plan);
        $this->assertSame(2, SalesFollowUpAttachment::count());
        $this->assertSame(2, $followUp->attachments->count());
        foreach ($followUp->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }

        $roadmap = $sales->roadmap($project->project_key, $opportunity->id);
        $qualified = collect($roadmap['nodes'])->firstWhere('key', 'qualified');
        $this->assertSame('current', $qualified['state']);
        $this->assertSame(1, $qualified['note_count']);
        $this->assertSame(2, $qualified['attachment_count']);
        $this->assertSame('solution', $roadmap['next_stage']);

        $sales->createQuote($project->project_key, $opportunity->id, [
            'currency' => 'USD',
            'subtotal_micros' => 500000000,
        ], $owner);

        $roadmap = $sales->roadmap($project->project_key, $opportunity->id);
        $solution = collect($roadmap['nodes'])->firstWhere('key', 'solution');
        $quoted = collect($roadmap['nodes'])->firstWhere('key', 'quoted');
        $this->assertSame('skipped', $solution['state'], 'A stage never entered must not be fabricated as completed.');
        $this->assertSame('current', $quoted['state']);
        $this->assertSame('quote', SalesOpportunityStageEvent::query()->where('to_stage', 'quoted')->value('source'));

        $sales->markWon($project->project_key, $opportunity->id, $owner);
        $closedRoadmap = $sales->roadmap($project->project_key, $opportunity->id);
        $this->assertTrue($closedRoadmap['closed']);
        $this->assertSame('won', $closedRoadmap['current_stage']);
        $this->assertSame('成交', $closedRoadmap['current_label']);
        $this->assertSame('won', collect($closedRoadmap['nodes'])->last()['key']);
        $this->assertSame('current', collect($closedRoadmap['nodes'])->last()['state']);
        $this->assertSame('close', SalesOpportunityStageEvent::query()->where('to_stage', 'won')->value('source'));
        $this->assertSame(1, SalesFollowUp::count());
    }

    public function test_lost_opportunity_can_be_reopened_without_erasing_close_history(): void
    {
        $project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
        $owner = User::factory()->create(['name' => 'Roadmap Owner', 'is_active' => true]);
        $company = Company::create([
            'name' => 'Reopen Customer',
            'status' => 'prospect',
            'identity_status' => 'verified',
        ]);
        BusinessProjectCompany::create([
            'business_project_id' => $project->id,
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'relationship_status' => 'prospect',
            'lifetime_revenue_micros' => 0,
        ]);

        $sales = $this->app->make(SalesOpportunityService::class);
        $opportunity = $sales->create($project->project_key, [
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'title' => 'Reopen opportunity',
            'currency' => 'USD',
            'pipeline_stage' => 'negotiating',
        ], $owner);

        $sales->markLost($project->project_key, $opportunity->id, 'budget_paused', 'Customer paused the project.', $owner);
        $lost = $sales->find($project->project_key, $opportunity->id);
        $this->assertSame('lost', $lost->status);
        $this->assertSame('budget_paused', $lost->lost_reason_code);
        $lostEvent = SalesOpportunityStageEvent::query()->where('opportunity_id', $opportunity->id)->where('to_stage', 'lost')->firstOrFail();
        $this->assertSame('close', $lostEvent->source);
        $this->assertStringContainsString('budget_paused', (string) $lostEvent->reason);

        $sales->reopen($project->project_key, $opportunity->id, 'qualified', 'Customer contacted us again with a restored budget.', $owner);

        $reopened = $sales->find($project->project_key, $opportunity->id);
        $this->assertSame('open', $reopened->status);
        $this->assertSame('qualified', $reopened->pipeline_stage);
        $this->assertNull($reopened->lost_at);
        $this->assertNull($reopened->lost_reason_code);
        $this->assertNull($reopened->lost_reason_note);

        $events = SalesOpportunityStageEvent::query()->where('opportunity_id', $opportunity->id)->orderBy('id')->get();
        $this->assertSame(['negotiating', 'lost', 'qualified'], $events->pluck('to_stage')->all());
        $this->assertSame('reopen', $events->last()->source);
        $this->assertSame('lost', $events->last()->from_stage);
        $this->assertSame('Customer contacted us again with a restored budget.', $events->last()->reason);

        $roadmap = $sales->roadmap($project->project_key, $opportunity->id);
        $this->assertFalse($roadmap['closed']);
        $this->assertSame('qualified', $roadmap['current_stage']);
        $this->assertSame('已确认需求', $roadmap['current_label']);
        $this->assertSame('completed', collect($roadmap['nodes'])->firstWhere('key', 'negotiating')['state'], 'A historically visited stage must stay visible after reopening to an earlier stage.');
        $this->assertNotNull(collect($roadmap['events'])->firstWhere('to_stage', 'lost'));
        $this->assertNotNull(collect($roadmap['events'])->firstWhere('source', 'reopen'));
    }
}
