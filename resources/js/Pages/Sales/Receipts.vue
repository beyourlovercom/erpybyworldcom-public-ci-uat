<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type AuditEvent = {
  id:number
  action:string
  actor_id:number|null
  actor_name:string
  source:string
  reason:string|null
  created_at:string|null
}
type Receipt = {
  id:number
  receipt_number:string
  amount_micros:number
  currency:string
  received_at:string|null
  payment_method:string
  entry_source:string
  provider_reference:string|null
  evidence_reference:string|null
  note:string|null
  status:'confirmed'|'voided'
  company:{id:number;name:string}|null
  opportunity:{id:number;opportunity_number:string;title:string}|null
  recorded_by:{id:number;name:string}|null
  voided_at:string|null
  void_reason:string|null
  voided_by:{id:number;name:string}|null
  audit_events:AuditEvent[]
}
type Total = {currency:string;amount_micros:number;receipt_count:number}

const props=defineProps<{
  project:{id:number;key:string;name:string}
  filters:{date_from:string;date_to:string;status:string|null;currency:string|null;search:string|null}
  confirmed_totals:Total[]
  receipts:Receipt[]
}>()

const filter=useForm({
  date_from:props.filters.date_from,
  date_to:props.filters.date_to,
  status:props.filters.status ?? '',
  currency:props.filters.currency ?? '',
  search:props.filters.search ?? '',
})
const paymentMethodLabel:Record<string,string>={bank_transfer:'银行转账',alipay:'支付宝',alibaba:'阿里',paypal:'PayPal',stripe:'Stripe',other:'其他'}
const auditActionLabel:Record<string,string>={
  'sales.payment_receipt.recorded':'登记回款',
  'sales.payment_receipt.voided':'作废回款',
}
const money=(micros:number,currency:string)=>new Intl.NumberFormat('zh-CN',{style:'currency',currency,maximumFractionDigits:2}).format(micros/1_000_000)
const formatDate=(value:string|null)=>value?new Date(value).toLocaleString('zh-CN',{hour12:false}):'—'
const isUrl=(value:string|null)=>Boolean(value && /^https?:\/\//i.test(value))
const applyFilters=()=>router.get(`/projects/${props.project.key}/sales/receipts`,{...filter.data(),currency:String(filter.currency||'').toUpperCase()},{preserveState:true,replace:true})
const resetFilters=()=>{
  filter.status=''
  filter.currency=''
  filter.search=''
  applyFilters()
}
</script>

<template>
  <Head :title="`${project.name} · 回款明细 · YBY ERP`"/>
  <AuthenticatedLayout title="回款明细" :breadcrumbs="['YBY ERP',project.name,'销售','回款明细']" canonical-topbar>
    <section class="customer-os-page receipt-ledger-page">
      <header class="sales-canonical-hero">
        <div class="sales-canonical-hero-copy">
          <p class="sales-canonical-kicker">REVENUE TRUTH V1 · R6</p>
          <h2>{{ project.name }} · 回款明细</h2>
          <p>只展示 Payment Receipt 真值。每一笔实收都可回溯到客户、商机、登记人和不可变审计记录。</p>
        </div>
        <div class="sales-canonical-actions">
          <Link class="customer-os-button" :href="`/projects/${project.key}`">老板看板</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="`/projects/${project.key}/sales`">销售 Pipeline</Link>
        </div>
      </header>

      <div class="sales-kpi-grid receipt-kpi-grid">
        <article v-for="total in confirmed_totals" :key="total.currency" class="sales-kpi-card is-green receipt-kpi-card">
          <span class="sales-kpi-icon">¥</span>
          <div><span>已确认实收 · {{ total.currency }}</span><strong>{{ money(total.amount_micros,total.currency) }}</strong><small>{{ total.receipt_count }} 笔 · 当前筛选范围</small></div>
        </article>
        <article v-if="!confirmed_totals.length" class="sales-kpi-card receipt-kpi-card">
          <span class="sales-kpi-icon">¥</span>
          <div><span>已确认实收</span><strong>—</strong><small>当前范围暂无已确认回款</small></div>
        </article>
      </div>

      <article class="sales-pipeline-card receipt-ledger-card">
        <div class="sales-pipeline-heading receipt-ledger-heading"><div><p class="sales-pipeline-kicker">¥</p><div><h3>回款账本</h3><p>按到账时间、状态、币种和客户/商机追踪真实回款，并保留完整审计链。</p></div></div></div>
        <div class="receipt-filter-row">
          <label><span>开始日期</span><input v-model="filter.date_from" type="date"></label>
          <label><span>结束日期</span><input v-model="filter.date_to" type="date"></label>
          <label><span>状态</span><select v-model="filter.status"><option value="">全部状态</option><option value="confirmed">已确认</option><option value="voided">已作废</option></select></label>
          <label><span>币种</span><input v-model="filter.currency" maxlength="3" placeholder="USD"></label>
          <label class="receipt-search"><span>搜索</span><input v-model="filter.search" placeholder="回款号 / 客户 / 商机 / 参考号" @keyup.enter="applyFilters"></label>
          <div class="receipt-filter-actions"><button type="button" class="customer-os-button" @click="resetFilters">重置</button><button type="button" class="customer-os-button customer-os-button-primary" @click="applyFilters">查询</button></div>
        </div>

        <div class="receipt-table-shell">
          <table class="customer-os-table receipt-table">
            <thead><tr><th>回款</th><th>到账时间</th><th>客户 / 商机</th><th>金额</th><th>渠道</th><th>登记</th><th>参考 / 凭证</th><th>状态</th><th>审计轨迹</th></tr></thead>
            <tbody v-if="receipts.length">
              <tr v-for="receipt in receipts" :key="receipt.id" :class="{'is-voided':receipt.status==='voided'}">
                <td><strong>{{ receipt.receipt_number }}</strong><small v-if="receipt.note">{{ receipt.note }}</small></td>
                <td>{{ formatDate(receipt.received_at) }}</td>
                <td>
                  <strong>{{ receipt.company?.name ?? '—' }}</strong>
                  <Link v-if="receipt.opportunity" class="receipt-opportunity-link" :href="`/projects/${project.key}/sales/opportunities/${receipt.opportunity.id}`">{{ receipt.opportunity.opportunity_number }}</Link>
                  <small v-if="receipt.opportunity">{{ receipt.opportunity.title }}</small>
                </td>
                <td><strong>{{ money(receipt.amount_micros,receipt.currency) }}</strong><small>{{ receipt.currency }}</small></td>
                <td>{{ paymentMethodLabel[receipt.payment_method] ?? receipt.payment_method }}</td>
                <td><strong>{{ receipt.recorded_by?.name ?? 'System' }}</strong><small>{{ receipt.entry_source==='manual'?'人工登记':receipt.entry_source }}</small></td>
                <td>
                  <div v-if="receipt.provider_reference">{{ receipt.provider_reference }}</div>
                  <a v-if="isUrl(receipt.evidence_reference)" :href="receipt.evidence_reference || '#'" target="_blank" rel="noreferrer">查看凭证</a>
                  <small v-else-if="receipt.evidence_reference">{{ receipt.evidence_reference }}</small>
                  <span v-if="!receipt.provider_reference && !receipt.evidence_reference">—</span>
                </td>
                <td>
                  <span class="receipt-status" :class="receipt.status">{{ receipt.status==='confirmed'?'已确认':'已作废' }}</span>
                  <small v-if="receipt.voided_at">{{ formatDate(receipt.voided_at) }}</small>
                  <small v-if="receipt.void_reason">{{ receipt.void_reason }}</small>
                </td>
                <td>
                  <div v-if="receipt.audit_events.length" class="audit-stack">
                    <div v-for="event in receipt.audit_events" :key="event.id" class="audit-event">
                      <span>{{ auditActionLabel[event.action] ?? event.action }}</span>
                      <strong>{{ event.actor_name }}</strong>
                      <small>{{ formatDate(event.created_at) }}</small>
                    </div>
                  </div>
                  <span v-else>—</span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!receipts.length" class="receipt-empty">当前筛选范围暂无回款记录。</div>
        </div>
        <p class="receipt-boundary">ⓘ 已作废记录保留用于审计，但不计入实收汇总。成交金额、应收和会计营收不会在此页被自动推导。</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.receipt-ledger-page{display:grid;gap:16px}.receipt-total-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.receipt-total-card{display:grid;gap:5px;padding:16px 18px;border:1px solid #dce8e3;border-radius:14px;background:#f5faf8}.receipt-total-card span{color:#567267;font-size:11px;font-weight:800}.receipt-total-card strong{color:#126b4d;font-size:26px}.receipt-total-card small{color:#87958f;font-size:10px}.receipt-total-card.is-empty{background:#fafbfb}.receipt-ledger-card{padding:16px;border:1px solid #dfe7e4;border-radius:15px;background:#fff}.receipt-filter-row{display:grid;grid-template-columns:150px 150px 130px 100px minmax(220px,1fr) auto;gap:10px;align-items:end}.receipt-filter-row label{display:grid;gap:5px}.receipt-filter-row label span{font-size:10px;font-weight:700;color:#66756f}.receipt-filter-row input,.receipt-filter-row select{min-height:38px;width:100%;border:1px solid #d7e0dd;border-radius:9px;padding:0 10px;background:#fff}.receipt-filter-actions{display:flex;gap:8px}.receipt-table-shell{margin-top:16px;overflow-x:auto}.receipt-table{min-width:1180px}.receipt-table td{vertical-align:top}.receipt-table td strong,.receipt-table td small{display:block}.receipt-table td small{margin-top:3px;color:#87948f;max-width:220px;white-space:normal}.receipt-opportunity-link{display:block;margin-top:4px;color:#087f5b;font-size:11px;font-weight:800;text-decoration:none}.receipt-table a{color:#087f5b;font-weight:700}.receipt-status{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:800}.receipt-status.confirmed{background:#e7f7ef;color:#087f5b}.receipt-status.voided{background:#f2f3f4;color:#879198}.is-voided{opacity:.65}.is-voided td:nth-child(4) strong{text-decoration:line-through}.audit-stack{display:grid;gap:6px;min-width:160px}.audit-event{display:grid;grid-template-columns:1fr auto;gap:2px 8px;padding-bottom:6px;border-bottom:1px dashed #e2e8e5}.audit-event:last-child{border-bottom:0}.audit-event span{font-size:10px;color:#53655d}.audit-event strong{font-size:10px;color:#263b32}.audit-event small{grid-column:1/-1;font-size:9px;color:#93a09b}.receipt-empty{padding:42px;text-align:center;color:#94a3b8}.receipt-boundary{margin:12px 0 0;padding:10px 12px;border-radius:9px;background:#eef7ff;color:#60758a;font-size:10px}@media(max-width:1100px){.receipt-filter-row{grid-template-columns:repeat(2,minmax(0,1fr))}.receipt-search{grid-column:1/-1}.receipt-filter-actions{grid-column:1/-1}}@media(max-width:640px){.receipt-filter-row{grid-template-columns:1fr}.receipt-search,.receipt-filter-actions{grid-column:auto}.receipt-filter-actions{display:grid;grid-template-columns:1fr 1fr}}

.receipt-ledger-page{display:grid;gap:16px;padding-bottom:30px}
.receipt-kpi-grid{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:0}
.receipt-kpi-card{min-height:124px}
.receipt-ledger-heading{margin-bottom:18px}
.receipt-filter-row{display:grid;grid-template-columns:150px 150px 130px 100px minmax(220px,1fr) auto;gap:10px;align-items:end}
.receipt-filter-row label{display:grid;gap:5px}.receipt-filter-row label span{font-size:10px;font-weight:750;color:#66756f}
.receipt-filter-row input,.receipt-filter-row select{min-height:42px;width:100%;border:1px solid #cdd8dd;border-radius:9px;padding:0 11px;background:#fff;color:#34475e;font:inherit;font-size:13px}
.receipt-filter-actions{display:flex;gap:8px}.receipt-filter-actions .customer-os-button{min-height:42px}
.receipt-table-shell{margin-top:14px;overflow-x:auto;border:1px solid #e4eaed;border-radius:12px;background:#fff}.receipt-table{min-width:1180px}
.receipt-boundary{margin:12px 0 0;padding:10px 12px;border:1px solid #dcebe5;border-radius:9px;background:#f3faf7;color:#60756b;font-size:10px}
@media(max-width:1100px){.receipt-filter-row{grid-template-columns:repeat(2,minmax(0,1fr))}.receipt-search,.receipt-filter-actions{grid-column:1/-1}}
@media(max-width:800px){.receipt-ledger-page .sales-canonical-actions{width:100%}.receipt-ledger-page .sales-canonical-actions .customer-os-button{flex:1}}
@media(max-width:640px){.receipt-filter-row{grid-template-columns:1fr}.receipt-search,.receipt-filter-actions{grid-column:auto}.receipt-filter-actions{display:grid;grid-template-columns:1fr 1fr}.receipt-kpi-grid{display:flex;overflow-x:auto}.receipt-kpi-card{min-width:240px}}
</style>
