<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Owner = { id:number; name:string; email:string }
type Company = { id:number; name:string }
type InquiryCase = {
  id:number; case_number:string; title:string; contact_name:string|null; contact_email:string|null; contact_phone:string|null;
  company_name_snapshot:string|null; country_snapshot:string|null; buyer_type:string|null; product_interest:string|null; quantity:string|null;
  source_channel:string; source_site_key:string|null; case_status:string; case_stage:string; handling_state:string;
  identity_resolution_state:string; first_inbound_at:string|null; last_inbound_at:string|null;
  company:{id:number;name:string}|null; owner:{id:number;name:string}|null
}
const props = defineProps<{
  project:{key:string;name:string}; filters:{status:string;stage:string;source:string};
  summary:{total:number;open:number;waiting:number;unresolved_identity:number};
  owners:Owner[]; companies:Company[]; cases:InquiryCase[]
}>()
const filters = reactive({ ...props.filters })
const query = ref('')
const ownerDraft = reactive<Record<number,string>>({})
const companyDraft = reactive<Record<number,string>>({})
props.cases.forEach((row) => {
  ownerDraft[row.id] = row.owner ? String(row.owner.id) : ''
  companyDraft[row.id] = row.company ? String(row.company.id) : ''
})
const apply = () => router.get(`/projects/${props.project.key}/inquiries`, filters, { preserveState:true, replace:true })
const assign = (row:InquiryCase) => router.post(
  `/projects/${props.project.key}/inquiries/${row.id}/assignment`,
  { owner_id: ownerDraft[row.id] === '' ? null : Number(ownerDraft[row.id]) },
  { preserveScroll:true },
)
const linkCompany = (row:InquiryCase) => {
  if (!companyDraft[row.id]) return
  router.post(`/projects/${props.project.key}/inquiries/${row.id}/identity/company`, { company_id:Number(companyDraft[row.id]) }, { preserveScroll:true })
}
const sourceLabel = (value:string) => ({website:'官网',whatsapp:'WhatsApp',xiaoman:'阿里小满',manual:'人工录入'} as Record<string,string>)[value] || value
const stageLabel = (value:string) => ({new:'新询盘',contacted:'已联系',qualified:'已验证',quoted:'已报价',negotiating:'谈判中'} as Record<string,string>)[value] || value
const confirmedIdentity = computed(() => Math.max(0, props.summary.total - props.summary.unresolved_identity))
const visibleCases = computed(() => {
  const needle = query.value.trim().toLowerCase()
  if (!needle) return props.cases
  return props.cases.filter((row) => [row.case_number,row.contact_name,row.contact_email,row.contact_phone,row.company?.name,row.company_name_snapshot,row.country_snapshot,row.product_interest]
    .filter(Boolean).some((value) => String(value).toLowerCase().includes(needle)))
})
const statusClass = (row:InquiryCase) => row.case_status === 'won' ? 'is-success' : row.case_status === 'lost' || row.case_status === 'spam' ? 'is-danger' : row.handling_state === 'waiting' ? 'is-waiting' : 'is-active'
const statusLabel = (row:InquiryCase) => row.case_status === 'won' ? '已成交' : row.case_status === 'lost' ? '已丢失' : row.case_status === 'spam' ? '垃圾' : row.handling_state === 'waiting' ? '待处理' : '跟进中'
</script>

<template>
  <Head :title="`${project.name} 询盘 · YBY ERP`" />
  <AuthenticatedLayout title="询盘" :breadcrumbs="['YBY ERP', project.name, '询盘']">
    <section class="customer-os-page project-inquiry-os">      <header class="project-canonical-hero">
        <div>
          <p class="customer-os-eyebrow">INQUIRY OS</p>
          <h1>{{ project.name }} · 询盘</h1>
          <p>集中收集全球询盘，智能分配、快速验证、持续跟进，提升转化效率。</p>
        </div>
        <div class="project-canonical-actions">
          <Link class="customer-os-button customer-os-button--secondary" :href="`/projects/${project.key}/customers`">客户</Link>
          <Link class="customer-os-button customer-os-button--primary" :href="`/projects/${project.key}/sales`">销售</Link>
        </div>
      </header>

      <div class="project-kpi-grid project-kpi-grid--five inquiry-kpis">
        <article class="project-kpi-card"><span class="project-kpi-icon is-blue">▤</span><div><span>全部询盘</span><strong>{{ summary.total }}</strong><small>Canonical Case 总数</small></div></article>
        <article class="project-kpi-card"><span class="project-kpi-icon is-green">⌁</span><div><span>开放询盘</span><strong>{{ summary.open }}</strong><small>当前仍在业务处理中</small></div></article>
        <article class="project-kpi-card"><span class="project-kpi-icon is-amber">◷</span><div><span>待处理</span><strong>{{ summary.waiting }}</strong><small>等待分配或首次处理</small></div></article>
        <article class="project-kpi-card"><span class="project-kpi-icon is-violet">?</span><div><span>身份待确认</span><strong>{{ summary.unresolved_identity }}</strong><small>尚未完成客户身份解析</small></div></article>
        <article class="project-kpi-card"><span class="project-kpi-icon is-emerald">✓</span><div><span>已确认身份</span><strong>{{ confirmedIdentity }}</strong><small>已完成客户身份解析</small></div></article>
      </div>

      <section class="customer-os-card inquiry-center-card">
        <div class="customer-os-section-heading">
          <div><span class="customer-os-section-icon">▥</span><div><h2>询盘中心</h2><p>管理真实渠道进入的潜在客户询盘，支持筛选、分配负责人、确认客户身份并持续跟进。</p></div></div>
        </div>
        <div class="inquiry-source-truth"><strong>数据真相：</strong>Google Ads 转化指标不会被当成客户询盘；只有进入 Canonical Case 的真实 Lead 才出现在这里。</div>
        <form class="inquiry-filter-row" @submit.prevent="apply">
          <label class="inquiry-search"><span>⌕</span><input v-model="query" type="search" placeholder="搜索 Case / 公司 / 联系人 / 邮箱" /></label>
          <select v-model="filters.source" aria-label="询盘来源"><option value="">全部来源</option><option value="website">官网</option><option value="whatsapp">WhatsApp</option><option value="xiaoman">阿里小满</option><option value="manual">人工录入</option></select>
          <select v-model="filters.status" aria-label="Case 状态"><option value="">全部状态</option><option value="open">开放</option><option value="won">成交</option><option value="lost">丢失</option><option value="spam">垃圾</option></select>
          <select v-model="filters.stage" aria-label="Case 阶段"><option value="">全部阶段</option><option value="new">新询盘</option><option value="contacted">已联系</option><option value="qualified">已验证</option><option value="quoted">已报价</option><option value="negotiating">谈判中</option></select>
          <button class="customer-os-button customer-os-button--primary" type="submit">查询</button>
        </form>        <div class="inquiry-table-shell">
          <table class="customer-os-table inquiry-table">
            <thead><tr><th>Case / 联系人</th><th>客户</th><th>来源</th><th>产品兴趣</th><th>负责人</th><th>客户身份</th><th>阶段</th><th>状态</th></tr></thead>
            <tbody>
              <tr v-for="row in visibleCases" :key="row.id">
                <td><strong>{{ row.case_number }}</strong><div>{{ row.contact_name || row.title }}</div><small>{{ row.contact_email || row.contact_phone || '—' }}</small></td>
                <td><strong>{{ row.company?.name || row.company_name_snapshot || '待识别' }}</strong><div>{{ row.country_snapshot || '—' }}</div></td>
                <td><strong>{{ sourceLabel(row.source_channel) }}</strong><div><small>{{ row.source_site_key || '—' }}</small></div></td>
                <td>{{ row.product_interest || '—' }}<div v-if="row.quantity"><small>数量：{{ row.quantity }}</small></div></td>
                <td><select v-model="ownerDraft[row.id]" class="compact-select" @change="assign(row)"><option value="">待分配</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select></td>
                <td>
                  <template v-if="row.company"><span class="inquiry-pill is-success">已确认</span><div><small>{{ row.company.name }}</small></div></template>
                  <template v-else-if="companies.length"><select v-model="companyDraft[row.id]" class="compact-select"><option value="">选择客户公司</option><option v-for="company in companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select><button class="link-button" type="button" :disabled="!companyDraft[row.id]" @click="linkCompany(row)">确认关联</button></template>
                  <template v-else><span class="inquiry-pill is-waiting">待确认</span><div><small>Customer OS 建立后关联</small></div></template>
                </td>
                <td><span class="inquiry-stage">{{ stageLabel(row.case_stage) }}</span></td>
                <td><span class="inquiry-pill" :class="statusClass(row)">{{ statusLabel(row) }}</span></td>
              </tr>
              <tr v-if="visibleCases.length === 0"><td colspan="8"><div class="inquiry-empty"><span>▤</span><strong>暂无匹配询盘</strong><p>{{ cases.length ? '调整搜索或筛选条件后重试。' : '接入官网 / WhatsApp / 阿里小满真实询盘后，这里才生成 Canonical Case。' }}</p></div></td></tr>
            </tbody>
          </table>
        </div>
        <footer class="inquiry-table-footer"><span>当前显示 {{ visibleCases.length }} 条 · 共 {{ summary.total }} 条询盘</span><span>真实 Case 数据</span></footer>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.compact-select{min-width:108px;max-width:160px;padding:7px 9px;border:1px solid #d7ddd9;border-radius:8px;background:#fff;font-size:12px}.link-button{display:block;margin-top:6px;border:0;background:transparent;color:#087a50;font-size:12px;cursor:pointer}.link-button:disabled{color:#94a3b8;cursor:not-allowed}.inquiry-table small{color:#6b7d86}.inquiry-stage{font-weight:650;color:#31434d}
</style>