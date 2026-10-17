<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Inquiries\Application\InquiryCaseService;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InquiryCaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_without_case_truth_stays_empty(): void
    {
        BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);

        $payload = $this->app->make(InquiryCaseService::class)->indexPayload('yby-irrigation');

        $this->assertSame(0, $payload['summary']['total']);
        $this->assertSame([], $payload['cases']);
    }

    public function test_case_truth_is_scoped_to_business_project(): void
    {
        $irrigation = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $bottle = BusinessProject::create(['project_key' => 'yby-bottle', 'name' => 'YBY Bottle', 'status' => 'active']);

        InquiryCase::create([
            'case_number' => 'YBY-CASE-000001',
            'business_project_id' => $irrigation->id,
            'title' => 'Wholesale irrigation inquiry',
            'source_channel' => 'website',
            'case_status' => 'open',
            'case_stage' => 'new',
            'handling_state' => 'waiting',
            'identity_resolution_state' => 'unresolved',
            'first_inbound_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $service = $this->app->make(InquiryCaseService::class);
        $irrigationPayload = $service->indexPayload('yby-irrigation');
        $bottlePayload = $service->indexPayload('yby-bottle');

        $this->assertSame(1, $irrigationPayload['summary']['total']);
        $this->assertSame('YBY-CASE-000001', $irrigationPayload['cases'][0]['case_number']);
        $this->assertSame(0, $bottlePayload['summary']['total']);
    }
}