<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Opportunity = {
  id:number; opportunity_number:string; title:string; status:string; pipeline_stage:string; currency:string
  expected_value_micros:number|null; probability_bps:number; expected_close_date:string|null
  next_follow_up_at:string|null; last_follow_up_at:string|null; lost_at:string|null; lost_reason_code:string|null; lost_reason_note:string|null; quotes_count:number
  company:{id:number;name:string;country_code:string|null;identity_status:string}|null
  owner:{id:number;name:string}|null
}
const props = defineProps<{
  project:{id:number;key:string;name:string}
  metrics:{open:number;quoted:number;won:number;lost:number;pipeline_value_micros:number}
  filters:Record<string,string|number|null>
  owners:Array<{id:number;name:string;email:string}>
  companies:Array<{id:number;name:string;identity_status:string;relationship_status:string}>
  opportunities:Opportunity[]
}>()
const createForm = useForm({company_id:'', owner_id:'', title:'', currency:'USD', expected_value:'', expected_close_date:'', next_follow_up_at:''})
const filter = useForm({status:String(props.filters.status ?? ''), stage:String(props.filters.stage ?? ''), owner_id:String(props.filters.owner_id ?? ''), search:String(props.filters.search ?? '')})
const money = (micros:number|null, currency='USD') => micros === null ? '—' : new Intl.NumberFormat('zh-CN',{style:'currency',currency,maximumFractionDigits:2}).format(micros/1_000_000)
const applyFilters = () => router.get(`/projects/${props.project.key}/sales`, {...filter.data()}, {preserveState:true, replace:true})
const stageLabel:Record<string,string>={discovery:'初步接洽',qualified:'已确认需求',solution:'方案阶段',quoted:'已报价',negotiating:'谈判中',won:'成交',lost:'丢单'}
const submitCreate = () => createForm.transform((data:any)=>({
  company_id:Number(data.company_id), owner_id:data.owner_id ? Number(data.owner_id) : null, title:data.title,
  currency:data.currency, expected_value_micros:data.expected_value === '' ? null : Math.round(Number(data.expected_value)*1_000_000),
  expected_close_date:data.expected_close_date || null, next_follow_up_at:data.next_follow_up_at || null,
})).post(`/projects/${props.project.key}/sales/opportunities`)
</script>

<template>
  <Head :title="`${project.name} · 销售 · YBY ERP`" />
  <AuthenticatedLayout title="销售" :breadcrumbs="['YBY ERP', project.name, '销售']" canonical-topbar>
    <section class="customer-os-page sales-canonical-page">
      <header class="sales-canonical-hero">
        <div class="sales-canonical-hero-copy">
          <p class="sales-canonical-kicker">SALES OS</p>
          <h2>{{ project.name }} · 销售</h2>
          <p>从已确认客户进入 Opportunity，记录跟进、报价与 Won / Lost 真相。</p>
        </div>
        <div class="sales-canonical-actions">
          <Link class="customer-os-button" :href="`/projects/${project.key}/sales/receipts`">回款明细</Link>
          <Link class="customer-os-button" :href="`/projects/${project.key}/customers`">客户</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="`/projects/${project.key}/inquiries`">询盘</Link>
        </div>
      </header>

      <div class="sales-kpi-grid">
        <article class="sales-kpi-card"><span class="sales-kpi-icon">🌱</span><div><span>进行中商机</span><strong>{{ metrics.open }}</strong><small>持续跟进的潜在机会</small></div></article>
        <article class="sales-kpi-card is-blue"><span class="sales-kpi-icon">▤</span><div><span>报价 / 谈判</span><strong>{{ metrics.quoted }}</strong><small>已提供报价，等待反馈</small></div></article>
        <article class="sales-kpi-card is-green"><span class="sales-kpi-icon">◆</span><div><span>已成交</span><strong>{{ metrics.won }}</strong><small>成功赢单的商机</small></div></article>
        <article class="sales-kpi-card is-lost"><span class="sales-kpi-icon">×</span><div><span>已丢单</span><strong>{{ metrics.lost }}</strong><small>需要复盘或等待重新联系</small></div></article>
        <article class="sales-kpi-card is-indigo"><span class="sales-kpi-icon">▥</span><div><span>Pipeline Value</span><strong>{{ money(metrics.pipeline_value_micros) }}</strong><small>商机预计总金额</small></div></article>
      </div>

      <article class="sales-pipeline-card">
        <div class="sales-pipeline-heading">
          <div><p class="sales-pipeline-kicker">▥</p><div><h3>销售 Pipeline</h3><p>金额是商机预计值，不等同于真实 Revenue；真实收入仍由订单/财务事实提供。</p></div></div>
        </div>
        <div class="sales-filter-row">
          <input v-model="filter.search" placeholder="搜索商机 / 公司" @keyup.enter="applyFilters">
          <select v-model="filter.status" @change="applyFilters"><option value="">全部状态</option><option value="open">进行中</option><option value="won">成交</option><option value="lost">丢单</option></select>
          <select v-model="filter.stage" @change="applyFilters"><option value="">全部阶段</option><option v-for="(label,key) in stageLabel" :key="key" :value="key" v-show="!['won','lost'].includes(String(key))">{{ label }}</option></select>
          <select v-model="filter.owner_id" @change="applyFilters"><option value="">全部负责人</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select>
          <button type="button" class="customer-os-button customer-os-button-primary" @click="applyFilters">查询</button>
        </div>

        <div class="sales-table-shell">
          <table class="customer-os-table sales-opportunity-table">
            <thead><tr><th>商机</th><th>公司</th><th>阶段</th><th>负责人</th><th>预计金额</th><th>概率</th><th>下次跟进</th><th>报价</th><th>操作</th></tr></thead>
            <tbody v-if="opportunities.length">
              <tr v-for="row in opportunities" :key="row.id" :class="{'sales-row-lost':row.status==='lost'}">
                <td><Link class="customer-os-company-link" :href="`/projects/${project.key}/sales/opportunities/${row.id}`">{{ row.opportunity_number }}</Link><small>{{ row.title }}</small><span v-if="row.status==='lost'" class="sales-lost-inline">已丢单</span></td>
                <td>{{ row.company?.name ?? '—' }}<small>{{ row.company?.country_code ?? '' }}</small></td>
                <td><template v-if="row.status==='lost'"><span class="sales-lost-badge">丢单</span><small class="sales-lost-reason">{{ row.lost_reason_code || '未记录原因' }}</small></template><template v-else>{{ stageLabel[row.pipeline_stage] ?? row.pipeline_stage }}</template></td>
                <td>{{ row.owner?.name ?? '待分配' }}</td>
                <td>{{ money(row.expected_value_micros,row.currency) }}</td>
                <td>{{ (row.probability_bps/100).toFixed(0) }}%</td>
                <td>{{ row.next_follow_up_at ?? '—' }}</td>
                <td>{{ row.quotes_count }}</td>
                <td><Link class="customer-os-row-link" :href="`/projects/${project.key}/sales/opportunities/${row.id}`">查看</Link></td>
              </tr>
            </tbody>
          </table>
          <div v-if="!opportunities.length" class="sales-empty-state">
            <div class="sales-empty-illustration"><span></span><i></i><b></b></div>
            <strong>暂无销售商机</strong>
            <p>当前还没有已确认客户。先从 7 条真实询盘完成 Company 身份确认，再进入销售 Pipeline。</p>
            <Link class="customer-os-button customer-os-button-primary" :href="`/projects/${project.key}/inquiries`">进入询盘中心 →</Link>
          </div>
        </div>
      </article>

      <article v-if="companies.length" class="sales-create-card">
        <div class="sales-create-heading"><h3>新建商机</h3><p>只允许基于已进入项目客户关系的 Company 创建。</p></div>
        <form class="sales-create-grid" @submit.prevent="submitCreate">
          <select v-model="createForm.company_id" required><option value="">选择客户公司</option><option v-for="company in companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select>
          <input v-model="createForm.title" required placeholder="商机标题">
          <select v-model="createForm.owner_id"><option value="">负责人（可选）</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select>
          <input v-model="createForm.expected_value" inputmode="decimal" placeholder="预计金额">
          <input v-model="createForm.currency" maxlength="3" placeholder="USD">
          <input v-model="createForm.expected_close_date" type="date">
          <input v-model="createForm.next_follow_up_at" type="datetime-local">
          <button class="customer-os-button customer-os-button-primary" :disabled="createForm.processing">创建商机</button>
        </form>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.sales-kpi-card.is-lost{border-color:#efcaca;background:linear-gradient(180deg,#fffafa 0%,#fff4f4 100%)}
.sales-kpi-card.is-lost .sales-kpi-icon{background:#f7dede;color:#b63f3f}
.sales-kpi-card.is-lost strong{color:#9d3434}
.sales-row-lost{background:#fffafa}
.sales-row-lost:hover{background:#fff4f4}
.sales-row-lost td:first-child{box-shadow:inset 3px 0 0 #c85a5a}
.sales-lost-inline{display:inline-flex;margin-top:5px;padding:2px 7px;border-radius:999px;background:#fde8e8;color:#ad4040;font-size:9px;font-weight:800}
.sales-lost-badge{display:inline-flex;padding:3px 8px;border-radius:999px;background:#f7dddd;color:#a83838;font-size:10px;font-weight:850}
.sales-lost-reason{display:block;margin-top:4px;max-width:180px;color:#9a6666;font-size:9px;white-space:normal}
</style>
