<?php
namespace Tests\Feature;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Application\AttributionChainService;
use App\Modules\Inquiries\Application\InquiryAttributionService;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
final class AttributionChainTest extends TestCase {
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp(); $r=dirname(__DIR__,2); if(!Schema::hasTable('sales_opportunities')) (require $r.'/database/migrations/2026_09_17_101500_create_sales_os_v1_tables.php')->up(); if(!Schema::hasTable('case_attributions')) (require $r.'/database/migrations/2026_09_17_133000_create_case_attributions_table.php')->up(); }
 public function test_resolved_attribution_projects_through_won_without_fabricating_revenue(): void {
  $project=BusinessProject::create(['project_key'=>'attr-chain','name'=>'Attr Chain','status'=>'active']); $owner=User::factory()->create(['is_active'=>true]);
  $company=Company::create(['name'=>'Chain Co','status'=>'prospect','identity_status'=>'verified']); $rel=BusinessProjectCompany::create(['business_project_id'=>$project->id,'company_id'=>$company->id,'owner_id'=>$owner->id,'relationship_status'=>'prospect','lifetime_revenue_micros'=>0]);
  $account=TrafficSourceAccount::create(['business_project_id'=>$project->id,'provider'=>'google_ads','external_account_id'=>'acc','name'=>'Google','status'=>'active']); $campaign=TrafficCampaign::create(['traffic_source_account_id'=>$account->id,'external_campaign_id'=>'camp','name'=>'Chain Campaign','status'=>'enabled']);
  LandingPage::create(['business_project_id'=>$project->id,'page_key'=>'chain','name'=>'Chain','canonical_url'=>'https://example.test/chain','path'=>'/chain','status'=>'active']);
  $case=InquiryCase::create(['case_number'=>'CHAIN-1','business_project_id'=>$project->id,'company_id'=>$company->id,'owner_id'=>$owner->id,'title'=>'Chain','source_channel'=>'website','source_connection_key'=>'site','source_external_id'=>'1','source_payload_hash'=>str_repeat('c',64),'case_status'=>'open','case_stage'=>'qualified','handling_state'=>'in_progress','identity_resolution_state'=>'resolved','first_inbound_at'=>now(),'last_inbound_at'=>now(),'attribution'=>['utm_source'=>'google','utm_medium'=>'cpc','utm_campaign'=>$campaign->name,'gclid'=>'g','source_url'=>'https://example.test/chain']]);
  $this->app->make(InquiryAttributionService::class)->resolveCase($case->id,true);
  $sales=$this->app->make(SalesOpportunityService::class); $opp=$sales->create('attr-chain',['case_id'=>$case->id,'company_id'=>$company->id,'owner_id'=>$owner->id,'title'=>'Chain Opp','expected_value_micros'=>100000000],$owner); $sales->markWon('attr-chain',$opp->id,$owner);
  $chain=$this->app->make(AttributionChainService::class)->project('attr-chain'); $row=collect($chain['rows'])->firstWhere('case_id',$case->id);
  $this->assertSame('resolved',$row['attribution_state']); $this->assertSame($campaign->id,$row['traffic_campaign_id']); $this->assertSame('won',$row['opportunity_status']); $this->assertSame(1,$chain['won']); $this->assertSame(100000000,$chain['won_value_micros']); $this->assertSame(0,$rel->fresh()->lifetime_revenue_micros);
 }
}
