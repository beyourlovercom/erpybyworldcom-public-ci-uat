<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Customers\Infrastructure\Models\BusinessProjectCompany;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inquiries\Application\InquiryIntakeService;
use App\Modules\Inquiries\Application\InquiryWorkflowService;
use App\Modules\Sales\Application\SalesOpportunityService;
use App\Modules\Sales\Application\PaymentReceiptService;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
final class LocalBusinessE2ETest extends TestCase {
 use RefreshDatabase;
 public function test_website_to_won_and_confirmed_receipt_isolated(): void {
  $project=BusinessProject::create(['project_key'=>'yby-irrigation','name'=>'YBY E2E Local','status'=>'active']);
  BusinessProjectSite::create(['business_project_id'=>$project->id,'site_key'=>'ybyirrigation-com','domain_normalized'=>'ybyirrigation.com','base_url'=>'https://ybyirrigation.com','status'=>'active','is_primary'=>true]);
  $owner=User::factory()->create(['name'=>'LOCAL-E2E-OWNER','is_active'=>true]);
  $input=['source_channel'=>'website','site'=>'https://ybyirrigation.com/','source_external_id'=>'LOCAL-E2E-UAT-20261009',
   'contact_name'=>'E2E Test Buyer','contact_email'=>'local-e2e-uat@example.invalid',
   'company_name'=>'LOCAL-E2E TEST COMPANY','country'=>'TZ','product_interest'=>'Drip irrigation',
   'first_inbound_at'=>'2026-10-09T11:00:00+08:00','last_inbound_at'=>'2026-10-09T11:00:00+08:00',
   'attribution'=>['utm_source'=>'google','utm_campaign'=>'local-e2e-uat']];
  $intake=app(InquiryIntakeService::class);
  $first=$intake->ingest($input); $second=$intake->ingest($input);
  $this->assertTrue($first['created']);$this->assertFalse($second['created']);
  $case=$first['case'];$this->assertSame($case->id,$second['case']->id);
  $company=Company::create(['name'=>'LOCAL-E2E TEST COMPANY','status'=>'prospect','identity_status'=>'verified']);
  $workflow=app(InquiryWorkflowService::class);
  $workflow->assign('yby-irrigation',$case->id,$owner->id,$owner);
  $workflow->linkCompany('yby-irrigation',$case->id,$company->id,$owner);
  $case->refresh();$this->assertSame('resolved',$case->identity_resolution_state);
  $relationship=BusinessProjectCompany::query()->where('business_project_id',$project->id)->where('company_id',$company->id)->firstOrFail();
  $sales=app(SalesOpportunityService::class);
  $data=['case_id'=>$case->id,'company_id'=>$company->id,'owner_id'=>$owner->id,'title'=>'LOCAL-E2E Test Opportunity','currency'=>'USD','expected_value_micros'=>1000000000,'pipeline_stage'=>'qualified'];
  $op=$sales->create('yby-irrigation',$data,$owner);
  $this->assertSame($op->id,$sales->create('yby-irrigation',$data,$owner)->id);
  $quote=$sales->createQuote('yby-irrigation',$op->id,['currency'=>'USD','subtotal_micros'=>1000000000,'discount_micros'=>0,'tax_micros'=>0],$owner);
  $won=$sales->markWon('yby-irrigation',$op->id,$owner);
  $this->assertSame('won',$won->status);
  $this->assertSame(0,$relationship->fresh()->lifetime_revenue_micros);
  $receipt=app(PaymentReceiptService::class)->record('yby-irrigation',$op->id,
   ['amount_micros'=>300000000,'currency'=>'USD','received_at'=>'2026-10-09 11:20:00','payment_method'=>'bank_transfer',
    'provider_reference'=>'LOCAL-E2E-NOT-REAL','evidence_reference'=>'local-synthetic-uat','note'=>'Synthetic isolated test'], $owner);
  $this->assertSame('confirmed',$receipt->status);
  $this->assertSame($company->id,$receipt->company_id);
  $totals=app(PaymentReceiptService::class)->confirmedTotalsForOpportunity('yby-irrigation',$op->id);
  $this->assertSame(300000000,collect($totals)->firstWhere('currency','USD')['amount_micros']);
  $this->assertSame(1,SalesOpportunity::count());$this->assertSame(1,SalesPaymentReceipt::count());
  fwrite(STDOUT,'E2E_ISOLATED_PASS case='.$case->id.' company='.$company->id.' opportunity='.$op->id.' receipt='.$receipt->id.PHP_EOL);
 }
}
